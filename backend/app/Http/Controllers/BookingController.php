<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'bookingStep1' => 'nullable|array',
            'bookingStep2' => 'nullable|array',
            'bookingStep3' => 'nullable|array',
            'bookingReview' => 'nullable|array',
            'forceNewBooking' => 'nullable|boolean',
            'consent' => 'required|array',
            'consent.groomingAgreementAccepted' => 'required|boolean',
            'consent.sedationConsentAccepted' => 'required|boolean',
            'consent.digitalSignature' => 'required|string|max:255',
            'consent.consentDate' => 'required|string|max:255',
        ]);

        $bookingStep1 = $validated['bookingStep1'] ?? [];
        $bookingStep2 = $validated['bookingStep2'] ?? [];
        $bookingStep3 = $validated['bookingStep3'] ?? [];
        $bookingReview = $validated['bookingReview'] ?? [];
        $forceNewBooking = (bool) ($validated['forceNewBooking'] ?? false);
        $consent = $validated['consent'];

        $bookingDateInput = $bookingReview['bookingDate']
            ?? $bookingStep2['bookingDate']
            ?? $bookingStep1['bookingDate']
            ?? null;

        if (!$bookingDateInput) {
            throw ValidationException::withMessages([
                'bookingDate' => 'Booking date is required.',
            ]);
        }

        $bookingDate = $this->normalizeBookingDate($bookingDateInput);
        $bookingTime = $bookingReview['bookingTime']
            ?? $bookingStep2['bookingTime']
            ?? $bookingStep1['bookingTime']
            ?? null;

        $ownerName = $consent['digitalSignature']
            ?? $bookingStep2['ownerName']
            ?? $bookingStep1['ownerName']
            ?? null;

        $ownerPhone = $bookingStep2['ownerPhone'] ?? $bookingStep1['ownerPhone'] ?? null;
        $pets = is_array($bookingReview['pets'] ?? null) ? $bookingReview['pets'] : [];
        $specialNotes = $this->extractSpecialNotesFromBookingPayload($bookingReview, $bookingStep3);
        $authUser = auth('sanctum')->user();
        $userId = $authUser?->user_id;

        if (!$forceNewBooking && $userId) {
            $existingBooking = $this->findActiveBookingForUser($userId);

            if ($existingBooking) {
                $existingSnapshot = $this->transformBookingResponse(
                    $existingBooking,
                    $this->extractMetadata($existingBooking->special_notes),
                );

                return response()->json([
                    'success' => false,
                    'code' => 'ACTIVE_BOOKING_EXISTS',
                    'message' => 'You already have an active booking. Do you want to book a different one or reschedule your existing booking?',
                    'existingBooking' => $existingSnapshot,
                ], 409);
            }
        }

        $nextBookingId = ((int) Booking::max('booking_id')) + 1;
        $nextQueueNumber = ((int) Booking::whereDate('booking_date', $bookingDate->toDateString())->max('queue_number')) + 1;
        $reference = sprintf('GRM-%s-%06d', $bookingDate->format('Ymd'), $nextBookingId);

        $metadata = [
            'bookingTime' => $bookingTime,
            'consent' => $consent,
            'pets' => $pets,
            'payload' => $validated,
            'specialNotes' => $specialNotes,
            'submittedAt' => now()->toIso8601String(),
        ];

        $booking = Booking::create([
            'booking_id' => $nextBookingId,
            'booking_reference' => $reference,
            'user_id' => $userId,
            'window_id' => null,
            'booking_date' => $bookingDate->toDateString(),
            'booking_type' => 'online',
            'status' => 'waiting to arrive',
            'queue_number' => $nextQueueNumber,
            'walkin_name' => $ownerName,
            'walkin_phone' => $ownerPhone,
            'total_amount' => 0,
            'special_notes' => $specialNotes !== '' ? $specialNotes : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking submitted successfully.',
            'booking' => $this->transformBookingResponse($booking, $metadata),
        ], 201);
    }

    public function show(string $reference)
    {
        $booking = $this->findBookingByReference($reference);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        $metadata = $this->extractMetadata($booking->special_notes);

        return response()->json([
            'success' => true,
            'booking' => $this->transformBookingResponse($booking, $metadata),
        ], 200);
    }

    private function normalizeBookingDate(string $value): Carbon
    {
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'bookingDate' => 'Booking date format is invalid.',
            ]);
        }
    }

    private function findBookingByReference(string $reference): ?Booking
    {
        return Booking::where('booking_reference', $reference)->first();
    }

    private function findActiveBookingForUser(int $userId): ?Booking
    {
        $now = now();
        $next24Hours = $now->copy()->addHours(24);

        $candidateBookings = Booking::where('user_id', $userId)
            ->whereNotIn('status', ['completed', 'cancelled', 'no show'])
            ->whereDate('booking_date', '>=', $now->toDateString())
            ->orderBy('booking_date')
            ->orderBy('booking_id')
            ->get();

        foreach ($candidateBookings as $booking) {
            $bookingDate = Carbon::parse($booking->booking_date)->startOfDay();

            // Do not alert for bookings scheduled beyond the next 24 hours.
            if ($bookingDate->gt($next24Hours)) {
                continue;
            }

            return $booking;
        }

        return null;
    }

    private function extractSpecialNotesFromBookingPayload(array $bookingReview, array $bookingStep3): string
    {
        $notes = [];
        $pets = is_array($bookingReview['pets'] ?? null) ? $bookingReview['pets'] : [];

        foreach ($pets as $pet) {
            if (!is_array($pet)) {
                continue;
            }

            $instruction = trim((string) ($pet['specialInstructions'] ?? ''));
            if ($instruction === '') {
                continue;
            }

            $petName = trim((string) ($pet['petName'] ?? ''));
            $notes[] = $petName !== ''
                ? "{$petName}: {$instruction}"
                : $instruction;
        }

        if (!empty($notes)) {
            return implode(' | ', $notes);
        }

        return trim((string) ($bookingStep3['specialInstructions'] ?? ''));
    }

    private function extractMetadata(?string $specialNotes): array
    {
        if (!is_string($specialNotes) || trim($specialNotes) === '') {
            return [];
        }

        $decoded = json_decode($specialNotes, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function transformBookingResponse(Booking $booking, array $metadata): array
    {
        $reference = $booking->booking_reference
            ?? sprintf('GRM-%s-%06d', Carbon::parse($booking->booking_date)->format('Ymd'), (int) $booking->booking_id);
        $payload = is_array($metadata['payload'] ?? null) ? $metadata['payload'] : [];
        $consent = is_array($metadata['consent'] ?? null) ? $metadata['consent'] : [];
        $pets = is_array($metadata['pets'] ?? null) ? $metadata['pets'] : [];
        $specialNotes = $this->resolveSpecialNotes($booking->special_notes, $metadata);

        return [
            'reference' => $reference,
            'status' => $booking->status,
            'ownerName' => $booking->walkin_name,
            'ownerPhone' => $booking->walkin_phone,
            'bookingDate' => Carbon::parse($booking->booking_date)->isoFormat('MMMM D, YYYY'),
            'bookingTime' => $metadata['bookingTime'] ?? null,
            'specialNotes' => $specialNotes,
            'payload' => $payload,
            'consent' => $consent,
            'pets' => $pets,
            'queueNumber' => $booking->queue_number,
            'bookingId' => (int) $booking->booking_id,
            'createdAt' => $booking->created_at ? Carbon::parse($booking->created_at)->toIso8601String() : null,
        ];
    }

    private function resolveSpecialNotes(?string $rawSpecialNotes, array $metadata): ?string
    {
        if (is_string($metadata['specialNotes'] ?? null) && trim($metadata['specialNotes']) !== '') {
            return trim($metadata['specialNotes']);
        }

        if (!is_string($rawSpecialNotes) || trim($rawSpecialNotes) === '') {
            return null;
        }

        $trimmed = trim($rawSpecialNotes);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            if (is_string($decoded['specialNotes'] ?? null) && trim($decoded['specialNotes']) !== '') {
                return trim($decoded['specialNotes']);
            }
            return null;
        }

        return $trimmed;
    }
}
