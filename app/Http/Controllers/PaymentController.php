<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Notification;

class PaymentController extends Controller
{
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'final_price'    => 'required|numeric|min:0.01',
            'amount_paid'    => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:cash,gcash,maya,card,others',
            'notes'          => 'nullable|string|max:500',
        ]);
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

        $payment = $this->createPaymentRecord($booking, $data);

        $booking->update([
            'status'      => 'archived',
            'paid'        => true,
            'archived_at' => now(),
        ]);

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
    // Does NOT change status — just flags the booking as paid so it
    // skips the payment step when grooming finishes.
    public function payNow(Request $request, $bookingId)
    {
        $data = $this->validatePayload($request);

        $booking = Booking::find($bookingId);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if (!in_array($booking->status, ['checked_in', 'in_progress'])) {
            return response()->json(['success' => false, 'message' => 'Early payment is only available for checked-in or in-progress bookings.'], 422);
        }

        if ($booking->paid) {
            return response()->json(['success' => false, 'message' => 'This booking has already been paid.'], 422);
        }

        if ($data['amount_paid'] < $data['final_price']) {
            return response()->json(['success' => false, 'message' => 'Amount paid cannot be less than the final price.'], 422);
        }

        $payment = $this->createPaymentRecord($booking, $data);

        $booking->update(['paid' => true]);

        return response()->json([
            'success'        => true,
            'message'        => 'Early payment recorded. Booking will be auto-released after grooming.',
            'change'         => $payment->change_amount,
            'final_price'    => $data['final_price'],
            'amount_paid'    => $data['amount_paid'],
            'payment_method' => $payment->payment_method,
            'paid_at'        => Carbon::parse($payment->paid_at)->format('M j, Y g:i A'),
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
        $search = $request->query('search', '');
        $date   = $request->query('date', '');

        $query = Payment::with([
            'booking.user',
            'booking.bookingPets.pet',
            'booking.bookingServices.service',
        ])->where('payment_status', 'paid')->orderBy('paid_at', 'desc');

        if ($date) {
            $query->whereDate('paid_at', $date);
        }

        if ($search) {
            $query->whereHas('booking.user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%");
            })->orWhereHas('booking.bookingPets.pet', function ($q) use ($search) {
                $q->where('pet_name', 'like', "%{$search}%");
            });
        }

        $transactions = $query->get()->map(fn($p) => $this->formatTransaction($p));

        return response()->json([
            'success'      => true,
            'transactions' => $transactions->values(),
            'total'        => $transactions->count(),
        ]);
    }

    // ── FORMAT ────────────────────────────────────────────

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
