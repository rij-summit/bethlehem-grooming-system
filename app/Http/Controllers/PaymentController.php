<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentLimitExceededException;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingPaymentSettlementService;
use App\Support\PaymentAmountLimit;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'final_price' => 'required|numeric|decimal:0,2|min:0',
            'amount_paid' => 'nullable|numeric|decimal:0,2|min:0',
            'payment_method' => 'nullable|in:cash',
            'notes' => 'nullable|string|max:500',
            'service_prices' => 'nullable|array',
            'service_prices.*.booking_service_id' => 'required_with:service_prices|integer',
            'service_prices.*.amount' => 'required_with:service_prices|numeric|decimal:0,2|min:0.01',
            'pet_sizes' => 'nullable|array',
            'pet_sizes.*.booking_pet_id' => 'required_with:pet_sizes|integer',
            'pet_sizes.*.size' => 'required_with:pet_sizes|in:small,medium,large,extra_large',
        ]);

        if ((float) $data['final_price'] > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('final price');
        }

        if ((float) ($data['amount_paid'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('amount paid');
        }

        if (array_key_exists('amount_paid', $data) && (float) $data['final_price'] > 0) {
            $maximum = PaymentAmountLimit::maximumFor((float) $data['final_price']);
            if ((float) $data['amount_paid'] > $maximum) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount paid cannot exceed '
                        ."\u{20B1}".number_format($maximum, 2).'.',
                ]);
            }
        }

        foreach ($data['service_prices'] ?? [] as $servicePrice) {
            if ((float) ($servicePrice['amount'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
                throw new PaymentLimitExceededException('service price');
            }
        }

        return $data;
    }

    private function updateBookingServicePrices(
        Booking $booking,
        array $servicePrices,
        array $editableBookingPetIds,
    ): void {
        $editableServiceIds = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->whereIn('booking_pet_id', $editableBookingPetIds)
            ->lockForUpdate()
            ->pluck('booking_service_id')
            ->map(fn ($id) => (int) $id);
        $allServiceIds = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->pluck('booking_service_id')
            ->map(fn ($id) => (int) $id);
        $submittedPrices = collect($servicePrices)->mapWithKeys(fn ($line) => [
            (int) $line['booking_service_id'] => $this->paymentReadiness()->centsToMoney(
                $this->paymentReadiness()->moneyToCents($line['amount']),
            ),
        ]);

        if ($submittedPrices->keys()->diff($allServiceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'One or more service prices do not belong to this booking.',
            ]);
        }

        if ($submittedPrices->keys()->diff($editableServiceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'Only services for pets eligible for this payment can be changed.',
            ]);
        }

        if ($editableServiceIds->diff($submittedPrices->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'Please submit a confirmed price for every normally finished pet service.',
            ]);
        }

        foreach ($submittedPrices as $bookingServiceId => $amount) {
            BookingService::query()
                ->where('booking_id', $booking->booking_id)
                ->where('booking_service_id', $bookingServiceId)
                ->update(['price_at_booking' => $amount]);
        }
    }

    private function updateBookingPetConfirmedSizes(Booking $booking, array $petSizes): void
    {
        if ($petSizes === [] || ! Schema::hasColumn('booking_pets', 'confirmed_size')) {
            return;
        }

        $bookingPets = BookingPet::query()
            ->where('booking_id', $booking->booking_id)
            ->with('pet')
            ->lockForUpdate()
            ->get()
            ->keyBy('booking_pet_id');
        $submitted = collect($petSizes)->mapWithKeys(fn ($petSize) => [
            (int) $petSize['booking_pet_id'] => $petSize['size'],
        ]);

        if ($submitted->count() !== count($petSizes)
            || $submitted->keys()->diff($bookingPets->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pet_sizes' => 'One or more confirmed pet sizes do not belong to this booking.',
            ]);
        }

        foreach ($submitted as $bookingPetId => $size) {
            $bookingPet = $bookingPets->get($bookingPetId);
            if ($bookingPet->confirmed_size && $bookingPet->confirmed_size !== $size) {
                $bookingPet->pet?->confirmClinicSize($size);
            }
            $bookingPet->confirmed_size = $size;
            $bookingPet->save();
        }
    }

    public function store(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);

        try {
            $result = DB::transaction(function () use ($request, $bookingId, $data) {
                $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return $this->transactionError('Booking not found.', 404);
                }

                if ($booking->status !== 'for_payment') {
                    return $this->transactionError('Booking is not awaiting payment.', 422);
                }

                if ($this->alreadyPaid($booking)) {
                    return $this->transactionError('This booking has already been paid.', 409);
                }

                $summary = $this->paymentReadiness()->summarize($booking, true);
                if (! $summary['payment_ready']) {
                    return $this->transactionError(
                        'Final payment is unavailable. '.$summary['payment_blocked_reason'],
                        422,
                    );
                }

                $finishedBookingPetIds = collect($summary['pets'])
                    ->where('payment_kind', 'finished')
                    ->pluck('booking_pet_id')
                    ->all();
                $this->updateBookingServicePrices(
                    $booking,
                    $data['service_prices'] ?? [],
                    $finishedBookingPetIds,
                );
                $this->updateBookingPetConfirmedSizes($booking, $data['pet_sizes'] ?? []);

                $summary = $this->paymentReadiness()->summarize($booking, true);
                $serverTotal = (string) $summary['final_booking_total'];
                $this->validateSubmittedTotal($data['final_price'], $serverTotal);
                [$amountTendered, $paymentMethod, $notes] = $this->resolvePaymentInput(
                    $data,
                    $serverTotal,
                );
                $payment = $this->paymentSettlement()->recordPaidPayment(
                    $booking,
                    $serverTotal,
                    $amountTendered,
                    $paymentMethod,
                    $notes,
                    $request->user()?->user_id,
                );

                $booking->update([
                    'status' => 'released',
                    'paid' => true,
                    'total_amount' => $serverTotal,
                    'archived_at' => null,
                ]);

                Notification::create([
                    'type' => 'payment_confirmed',
                    'booking_id' => $booking->booking_id,
                    'message' => "Payment received for booking {$booking->booking_reference}.",
                    'is_read' => false,
                    'created_at' => $payment->paid_at ?? now(),
                ]);

                return compact('booking', 'payment', 'summary');
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'This booking was paid by another request. Refresh to view the completed payment.',
            ], 409);
        }

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'message' => 'Payment processed. Booking is ready for pickup.',
            'booking_status' => $result['booking']->status,
            'change' => $payment->change_amount,
            'final_price' => $payment->total_amount,
            'amount_paid' => $payment->amount_tendered,
            'payment_method' => $payment->payment_method,
            'payment_method_label' => ucfirst((string) $payment->payment_method),
            'paid_at' => Carbon::parse($payment->paid_at)->format('M j, Y g:i A'),
            'payment_summary' => $result['summary'],
        ]);
    }

    public function payNow(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);

        try {
            $result = DB::transaction(function () use ($request, $bookingId, $data) {
                $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return $this->transactionError('Booking not found.', 404);
                }

                if (! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                    return $this->transactionError(
                        'Early payment is only available for checked-in or in-progress bookings.',
                        422,
                    );
                }

                if ($this->alreadyPaid($booking)) {
                    return $this->transactionError('This booking has already been paid.', 409);
                }

                $bookingPets = $booking->bookingPets()
                    ->with('pet:pet_id,pet_name')
                    ->lockForUpdate()
                    ->get();
                $this->updateBookingServicePrices(
                    $booking,
                    $data['service_prices'] ?? [],
                    $bookingPets->pluck('booking_pet_id')->all(),
                );
                $this->updateBookingPetConfirmedSizes($booking, $data['pet_sizes'] ?? []);
                $serverTotalCents = BookingService::query()
                    ->where('booking_id', $booking->booking_id)
                    ->lockForUpdate()
                    ->get(['price_at_booking'])
                    ->sum(fn (BookingService $line) => $this->paymentReadiness()
                        ->moneyToCents($line->price_at_booking));

                if ($serverTotalCents <= 0) {
                    return $this->transactionError('Pay Now requires a positive booking total.', 422);
                }

                $serverTotal = $this->paymentReadiness()->centsToMoney($serverTotalCents);
                $this->validateSubmittedTotal($data['final_price'], $serverTotal);
                [$amountTendered, $paymentMethod, $notes] = $this->resolvePaymentInput(
                    $data,
                    $serverTotal,
                );
                $payment = $this->paymentSettlement()->recordPaidPayment(
                    $booking,
                    $serverTotal,
                    $amountTendered,
                    $paymentMethod,
                    $notes,
                    $request->user()?->user_id,
                );
                $allPetsFinished = $bookingPets->isNotEmpty()
                    && $bookingPets->every(fn (BookingPet $pet) => $pet->grooming_state === BookingPet::GROOMING_STATE_FINISHED
                        && $pet->grooming_end_time !== null
                    );
                $remainingPets = $bookingPets->count()
                    - $bookingPets->where('grooming_state', BookingPet::GROOMING_STATE_FINISHED)->count();
                $updates = ['paid' => true, 'total_amount' => $serverTotal];

                if ($allPetsFinished) {
                    $updates['status'] = 'released';
                    $updates['grooming_finished_at'] = $booking->grooming_finished_at ?? now();
                }

                $booking->update($updates);

                Notification::create([
                    'type' => 'payment_confirmed',
                    'booking_id' => $booking->booking_id,
                    'message' => "Payment received for booking {$booking->booking_reference}.",
                    'is_read' => false,
                    'created_at' => $payment->paid_at ?? now(),
                ]);

                return compact('booking', 'payment', 'allPetsFinished', 'remainingPets');
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'This booking was paid by another request. Refresh to view the completed payment.',
            ], 409);
        }

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'message' => $result['allPetsFinished']
                ? 'Payment recorded. Booking is ready for pickup.'
                : 'Early payment recorded. Booking will remain in its current schedule until every pet is finished.',
            'change' => $payment->change_amount,
            'final_price' => $payment->total_amount,
            'amount_paid' => $payment->amount_tendered,
            'payment_method' => $payment->payment_method,
            'paid_at' => Carbon::parse($payment->paid_at)->format('M j, Y g:i A'),
            'all_pets_finished' => $result['allPetsFinished'],
            'remaining_pets' => $result['remainingPets'],
            'booking_status' => $result['booking']->status,
        ]);
    }

    /** Move an already-paid legacy For Payment booking into To Be Picked Up. */
    public function release($bookingId)
    {
        $result = DB::transaction(function () use ($bookingId) {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return $this->transactionError('Booking not found.', 404);
            }

            if ($booking->status !== 'for_payment') {
                return $this->transactionError('Booking is not in For Payment status.', 422);
            }

            if (! $booking->paid) {
                return $this->transactionError('Booking has not been paid yet.', 422);
            }

            $booking->update(['status' => 'released', 'archived_at' => null]);

            return compact('booking');
        });

        if (isset($result['error'])) {
            return $this->transactionErrorResponse($result);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking is ready for physical pickup.',
            'booking_status' => 'released',
        ]);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $period = (string) $request->query('period', 'day');
        $date = (string) $request->query('date', '');
        $week = (string) $request->query('week', '');
        $month = (string) $request->query('month', '');
        $year = (string) $request->query('year', '');

        if (! in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction period.'], 422);
        }
        if ($period === 'day' && $date && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction date.'], 422);
        }
        if ($period === 'week' && $week && ! $this->isValidWeekValue($week)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction week.'], 422);
        }
        if ($period === 'month' && $month && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction month.'], 422);
        }
        if ($period === 'year' && $year && ! preg_match('/^\d{4}$/', $year)) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid transaction year.'], 422);
        }

        $query = Payment::with(['booking.user'])
            ->where('payment_status', 'paid')
            ->orderBy('paid_at', 'desc');
        $this->applyPeriodFilter($query, $period, $date, $week, $month, $year);

        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function ($query) use ($search, $nameTerms) {
                $query->whereHas('booking.user', function ($userQuery) use ($search, $nameTerms) {
                    $userQuery->where(function ($nameQuery) use ($search, $nameTerms) {
                        $nameQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere(function ($fullNameQuery) use ($nameTerms) {
                                foreach ($nameTerms as $term) {
                                    $fullNameQuery->where(function ($termQuery) use ($term) {
                                        $termQuery->where('first_name', 'like', "%{$term}%")
                                            ->orWhere('last_name', 'like', "%{$term}%");
                                    });
                                }
                            });
                    });
                })->orWhereHas('booking.bookingPets.pet', fn ($petQuery) => $petQuery->where('pet_name', 'like', "%{$search}%")
                );
            });
        }

        $transactions = $query->get()->map(fn (Payment $payment) => $this->formatTransaction($payment));

        return response()->json([
            'success' => true,
            'period' => $period,
            'date' => $date ?: null,
            'week' => $week ?: null,
            'month' => $month ?: null,
            'year' => $year ?: null,
            'transactions' => $transactions->values(),
            'total' => $transactions->count(),
        ]);
    }

    private function applyPeriodFilter($query, string $period, string $date, string $week, string $month, string $year): void
    {
        if ($period === 'day' && $date) {
            $query->whereDate('paid_at', $date);
        } elseif ($period === 'week' && $week) {
            [$start, $end] = $this->weekRange($week);
            $query->whereDate('paid_at', '>=', $start->toDateString())
                ->whereDate('paid_at', '<=', $end->toDateString());
        } elseif ($period === 'month' && $month) {
            [$selectedYear, $selectedMonth] = explode('-', $month);
            $query->whereYear('paid_at', (int) $selectedYear)
                ->whereMonth('paid_at', (int) $selectedMonth);
        } elseif ($period === 'year' && $year) {
            $query->whereYear('paid_at', (int) $year);
        }
    }

    private function isValidWeekValue(string $week): bool
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
            return false;
        }

        $year = (int) $matches[1];
        $weekNumber = (int) $matches[2];

        return $year > 0
            && $weekNumber > 0
            && $weekNumber <= Carbon::create($year, 12, 28)->isoWeek();
    }

    private function weekRange(string $week): array
    {
        preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches);
        $start = Carbon::now()->setISODate((int) $matches[1], (int) $matches[2], 1)->startOfDay();

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }

    private function formatTransaction(Payment $payment): array
    {
        $booking = $payment->booking;
        $summary = $booking
            ? $this->paymentReadiness()->summarize($booking)
            : ['pets' => []];
        $pets = collect($summary['pets'] ?? []);
        $petName = $pets->pluck('pet_name')->filter()->implode(', ') ?: '—';
        $serviceLabel = $pets->flatMap(fn (array $pet) => $pet['service_breakdown'] ?? [])
            ->pluck('label')->filter()->unique()->implode(', ') ?: 'Grooming';

        return [
            'id' => $payment->getKey(),
            'bookingId' => $booking?->booking_id,
            'reference' => $booking?->booking_reference ?? '—',
            'ownerName' => trim(($booking?->user?->first_name ?? '').' '.($booking?->user?->last_name ?? '')),
            'petName' => $petName,
            'serviceLabel' => $serviceLabel,
            'finalPrice' => (float) $payment->total_amount,
            'amountPaid' => (float) $payment->amount_tendered,
            'changeGiven' => (float) $payment->change_amount,
            'paymentMethod' => $payment->payment_method,
            'paymentMethodLabel' => ucfirst((string) $payment->payment_method),
            'notes' => $payment->notes,
            'paidAt' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'paidAtFormatted' => $payment->paid_at?->format('g:i A') ?? '—',
            'dateKey' => $payment->paid_at?->toDateString() ?? 'unknown',
            'paymentSummary' => $summary,
        ];
    }

    private function validateSubmittedTotal(string|int|float $submitted, string $serverTotal): void
    {
        if ($this->paymentReadiness()->moneyToCents($submitted)
            !== $this->paymentReadiness()->moneyToCents($serverTotal)) {
            throw ValidationException::withMessages([
                'final_price' => 'The submitted total does not match the server-calculated booking total.',
            ]);
        }
    }

    private function resolvePaymentInput(
        array $data,
        string $serverTotal,
    ): array {
        $money = $this->paymentReadiness();
        $totalCents = $money->moneyToCents($serverTotal);
        $amountCents = $money->moneyToCents($data['amount_paid'] ?? 0);

        if ($totalCents <= 0) {
            throw ValidationException::withMessages([
                'final_price' => 'A grooming payment requires a positive total.',
            ]);
        }

        if (! array_key_exists('amount_paid', $data)) {
            throw ValidationException::withMessages(['amount_paid' => 'Amount paid is required.']);
        }
        if ($amountCents < $totalCents) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid cannot be less than the final price.',
            ]);
        }

        $maximum = PaymentAmountLimit::maximumFor($totalCents / 100);
        if ($amountCents > (int) round($maximum * 100)) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid cannot exceed ₱'.number_format($maximum, 2).'.',
            ]);
        }

        return [
            $money->centsToMoney($amountCents),
            $data['payment_method'] ?? 'cash',
            $data['notes'] ?? null,
        ];
    }

    private function alreadyPaid(Booking $booking): bool
    {
        return (bool) $booking->paid
            || Payment::query()
                ->where('booking_id', $booking->booking_id)
                ->where('payment_status', 'paid')
                ->lockForUpdate()
                ->exists();
    }

    private function transactionError(string $message, int $status): array
    {
        return ['error' => compact('message', 'status')];
    }

    private function transactionErrorResponse(array $result)
    {
        return response()->json([
            'success' => false,
            'message' => $result['error']['message'],
        ], $result['error']['status']);
    }

    private function paymentReadiness(): GroomingPaymentReadinessService
    {
        return app(GroomingPaymentReadinessService::class);
    }

    private function paymentSettlement(): GroomingPaymentSettlementService
    {
        return app(GroomingPaymentSettlementService::class);
    }
}
