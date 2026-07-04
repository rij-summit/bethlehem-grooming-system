<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Payment;
use App\Models\Notification;
use App\Exceptions\PaymentLimitExceededException;
use App\Support\PaymentAmountLimit;

class PaymentController extends Controller
{
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'final_price'                         => 'required|numeric|min:0.01',
            'amount_paid'                         => 'required|numeric|min:0.01',
            'payment_method'                      => 'nullable|in:cash,gcash,maya,card,others',
            'notes'                               => 'nullable|string|max:500',
            'service_prices'                      => 'nullable|array',
            'service_prices.*.booking_service_id' => 'required_with:service_prices|integer',
            'service_prices.*.amount'             => 'required_with:service_prices|numeric|min:0.01',
        ]);

        if ($data['final_price'] > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('final price');
        }

        if ($data['amount_paid'] > PaymentLimitExceededException::MAX_VALUE) {
            throw new PaymentLimitExceededException('amount paid');
        }

        $maximumAmountPaid = PaymentAmountLimit::maximumFor((float) $data['final_price']);

        if ((float) $data['amount_paid'] > $maximumAmountPaid) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid cannot exceed ₱' . number_format($maximumAmountPaid, 2) . '.',
            ]);
        }

        foreach ($data['service_prices'] ?? [] as $servicePrice) {
            if (($servicePrice['amount'] ?? 0) > PaymentLimitExceededException::MAX_VALUE) {
                throw new PaymentLimitExceededException('service price');
            }
        }

        $servicePriceTotal = round(collect($data['service_prices'] ?? [])->sum(
            fn($servicePrice) => (float) ($servicePrice['amount'] ?? 0),
        ), 2);

        if ($servicePriceTotal > 0 && abs($servicePriceTotal - round((float) $data['final_price'], 2)) > 0.01) {
            throw ValidationException::withMessages([
                'final_price' => 'Final price must match the submitted service prices.',
            ]);
        }

        return $data;
    }

    private function updateBookingServicePrices(Booking $booking, array $servicePrices): void
    {
        $booking->loadMissing('bookingServices');

        $bookedServiceIds = $booking->bookingServices
            ->pluck('booking_service_id')
            ->map(fn($id) => (int) $id);

        if ($bookedServiceIds->isEmpty()) {
            return;
        }

        if (empty($servicePrices)) {
            throw ValidationException::withMessages([
                'service_prices' => 'Please submit a confirmed price for every booked service.',
            ]);
        }

        $submittedPrices = collect($servicePrices)->mapWithKeys(function ($servicePrice) {
            return [
                (int) $servicePrice['booking_service_id'] => round((float) $servicePrice['amount'], 2),
            ];
        });

        if ($submittedPrices->keys()->diff($bookedServiceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'One or more service prices do not belong to this booking.',
            ]);
        }

        if ($bookedServiceIds->diff($submittedPrices->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'service_prices' => 'Please submit a confirmed price for every booked service.',
            ]);
        }

        foreach ($submittedPrices as $bookingServiceId => $amount) {
            BookingService::where('booking_id', $booking->booking_id)
                ->where('booking_service_id', $bookingServiceId)
                ->update(['price_at_booking' => $amount]);
        }
    }

    private function createPaymentRecord(Booking $booking, array $data): Payment
    {
        $change = round($data['amount_paid'] - $data['final_price'], 2);

        return Payment::create([
            'booking_id'     => $booking->booking_id,
            'total_amount'   => $data['final_price'],
            'amount_tendered'=> $data['amount_paid'],
            'change_amount'  => $change,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_status' => 'paid',
            'notes'          => $data['notes'] ?? null,
            'paid_at'        => now(),
        ]);
    }

    // ── PROCESS PAYMENT ───────────────────────────────────
    // for_payment → archived  (creates payment record)
    public function store(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);

        $booking = Booking::with(['user', 'bookingPets.pet', 'bookingServices.service'])
            ->find($bookingId);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'for_payment') {
            return response()->json(['success' => false, 'message' => 'Booking is not awaiting payment.'], 422);
        }

        if ($data['amount_paid'] < $data['final_price']) {
            return response()->json(['success' => false, 'message' => 'Amount paid cannot be less than the final price.'], 422);
        }

        $payment = DB::transaction(function () use ($booking, $data) {
            $this->updateBookingServicePrices($booking, $data['service_prices'] ?? []);
            $payment = $this->createPaymentRecord($booking, $data);

            $booking->update([
                'status'       => 'archived',
                'paid'         => true,
                'total_amount' => $data['final_price'],
                'archived_at'  => now(),
            ]);

            return $payment;
        });

        return response()->json([
            'success'        => true,
            'message'        => 'Payment processed. Booking archived.',
            'change'         => $payment->change_amount,
            'final_price'    => $data['final_price'],
            'amount_paid'    => $data['amount_paid'],
            'payment_method' => $payment->payment_method,
            'paid_at'        => Carbon::parse($payment->paid_at)->format('M j, Y g:i A'),
        ]);
    }

    // ── PAY NOW (early payment from checked_in or in_progress) ───────────
    // Records owner-level payment without advancing a booking that still has
    // queued or in-progress pets. The final pet completion performs the move.
    public function payNow(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);

        $result = DB::transaction(function () use ($bookingId, $data) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

            if (!$booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if (!in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                return ['error' => [
                    'message' => 'Early payment is only available for checked-in or in-progress bookings.',
                    'status' => 422,
                ]];
            }

            if ($booking->paid) {
                return ['error' => ['message' => 'This booking has already been paid.', 'status' => 422]];
            }

            if ($data['amount_paid'] < $data['final_price']) {
                return ['error' => ['message' => 'Amount paid cannot be less than the final price.', 'status' => 422]];
            }

            $this->updateBookingServicePrices($booking, $data['service_prices'] ?? []);
            $payment = $this->createPaymentRecord($booking, $data);
            $petCount = $booking->bookingPets()->count();
            $remainingPets = $booking->bookingPets()
                ->whereNull('grooming_end_time')
                ->count();
            $allPetsFinished = $petCount > 0 && $remainingPets === 0;

            $bookingUpdates = [
                'paid'         => true,
                'total_amount' => $data['final_price'],
            ];

            // This is a defensive concurrency guard. During the normal UI flow,
            // the final Finished action is what advances an early-paid booking.
            if ($allPetsFinished) {
                $bookingUpdates['status'] = 'released';
                $bookingUpdates['grooming_finished_at'] = $booking->grooming_finished_at ?? now();
            }

            $booking->update($bookingUpdates);

            return [
                'booking' => $booking,
                'payment' => $payment,
                'allPetsFinished' => $allPetsFinished,
                'remainingPets' => $remainingPets,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $payment = $result['payment'];

        return response()->json([
            'success'        => true,
            'message'        => $result['allPetsFinished']
                ? 'Payment recorded. Booking is ready for pickup.'
                : 'Early payment recorded. Booking will remain in its current schedule until every pet is finished.',
            'change'         => $payment->change_amount,
            'final_price'    => $data['final_price'],
            'amount_paid'    => $data['amount_paid'],
            'payment_method' => $payment->payment_method,
            'paid_at'        => Carbon::parse($payment->paid_at)->format('M j, Y g:i A'),
            'all_pets_finished' => $result['allPetsFinished'],
            'remaining_pets' => $result['remainingPets'],
            'booking_status' => $result['booking']->status,
        ]);
    }

    // ── RELEASE (already-paid booking) ───────────────────
    // for_payment + paid=true → archived
    public function release($bookingId)
    {
        $booking = Booking::find($bookingId);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'for_payment') {
            return response()->json(['success' => false, 'message' => 'Booking is not in For Payment status.'], 422);
        }

        if (!$booking->paid) {
            return response()->json(['success' => false, 'message' => 'Booking has not been paid yet.'], 422);
        }

        $booking->update([
            'status'      => 'archived',
            'archived_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking released and archived.',
        ]);
    }

    // ── TRANSACTION LIST ──────────────────────────────────
    // GET /admin/transactions
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $period = $request->query('period', 'day');
        $date   = $request->query('date', '');
        $week   = $request->query('week', '');
        $month  = $request->query('month', '');
        $year   = $request->query('year', '');

        if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid transaction period.',
            ], 422);
        }

        if ($period === 'day' && $date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid transaction date.',
            ], 422);
        }

        if ($period === 'week' && $week && !$this->isValidWeekValue($week)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid transaction week.',
            ], 422);
        }

        if ($period === 'month' && $month && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid transaction month.',
            ], 422);
        }

        if ($period === 'year' && $year && !preg_match('/^\d{4}$/', $year)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid transaction year.',
            ], 422);
        }

        $query = Payment::with([
            'booking.user',
            'booking.bookingPets.pet',
            'booking.bookingServices.service',
        ])->where('payment_status', 'paid')->orderBy('paid_at', 'desc');

        $this->applyPeriodFilter($query, $period, $date, $week, $month, $year);

        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($q) use ($search, $nameTerms) {
                $q->whereHas('booking.user', function ($q2) use ($search, $nameTerms) {
                    $q2->where(function ($nameQuery) use ($search, $nameTerms) {
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
                })->orWhereHas('booking.bookingPets.pet', function ($q2) use ($search) {
                    $q2->where('pet_name', 'like', "%{$search}%");
                });
            });
        }

        $transactions = $query->get()->map(fn($p) => $this->formatTransaction($p));

        return response()->json([
            'success'      => true,
            'period'       => $period,
            'date'         => $date ?: null,
            'week'         => $week ?: null,
            'month'        => $month ?: null,
            'year'         => $year ?: null,
            'transactions' => $transactions->values(),
            'total'        => $transactions->count(),
        ]);
    }

    private function applyPeriodFilter($query, string $period, string $date, string $week, string $month, string $year): void
    {
        if ($period === 'day' && $date) {
            $query->whereDate('paid_at', $date);
            return;
        }

        if ($period === 'week' && $week) {
            [$weekStart, $weekEnd] = $this->weekRange($week);
            $query->whereDate('paid_at', '>=', $weekStart->toDateString())
                ->whereDate('paid_at', '<=', $weekEnd->toDateString());
            return;
        }

        if ($period === 'month' && $month) {
            [$selectedYear, $selectedMonth] = explode('-', $month);
            $query->whereYear('paid_at', (int) $selectedYear)
                ->whereMonth('paid_at', (int) $selectedMonth);
            return;
        }

        if ($period === 'year' && $year) {
            $query->whereYear('paid_at', (int) $year);
        }
    }

    // ── FORMAT ────────────────────────────────────────────

    private function isValidWeekValue(string $week): bool
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
            return false;
        }

        $year = (int) $matches[1];
        $weekNumber = (int) $matches[2];
        if ($year < 1 || $weekNumber < 1) {
            return false;
        }

        $lastIsoWeek = Carbon::create($year, 12, 28)->isoWeek();
        return $weekNumber <= $lastIsoWeek;
    }

    private function weekRange(string $week): array
    {
        preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches);

        $weekStart = Carbon::now()
            ->setISODate((int) $matches[1], (int) $matches[2], 1)
            ->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        return [$weekStart, $weekEnd];
    }

    private function formatTransaction(Payment $payment): array
    {
        $booking  = $payment->booking;
        $user     = $booking?->user;
        $bpets    = $booking?->bookingPets ?? collect();
        $firstPet = $bpets->first()?->pet;

        $petName = $firstPet?->pet_name ?? '—';
        if ($bpets->count() > 1) {
            $petName .= ' +' . ($bpets->count() - 1) . ' more';
        }

        $bookedServices = $booking?->bookingServices ?? collect();
        $serviceLabel   = $bookedServices
            ->map(fn($bs) => $bs->service?->service_name)
            ->filter()->unique()->implode(', ') ?: 'Grooming';

        return [
            'id'            => $payment->payment_id,
            'bookingId'     => $booking?->booking_id,
            'reference'     => $booking?->booking_reference ?? '—',
            'ownerName'     => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
            'petName'       => $petName,
            'serviceLabel'  => $serviceLabel,
            'finalPrice'    => (float) $payment->total_amount,
            'amountPaid'    => (float) $payment->amount_tendered,
            'changeGiven'   => (float) $payment->change_amount,
            'paymentMethod' => $payment->payment_method,
            'notes'         => $payment->notes,
            'paidAt'        => $payment->paid_at
                ? Carbon::parse($payment->paid_at)->format('Y-m-d H:i:s')
                : null,
            'paidAtFormatted' => $payment->paid_at
                ? Carbon::parse($payment->paid_at)->format('g:i A')
                : '—',
            'dateKey'       => $payment->paid_at
                ? Carbon::parse($payment->paid_at)->toDateString()
                : 'unknown',
        ];
    }
}
