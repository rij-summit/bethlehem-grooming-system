<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ChatbotBookingStatusService
{
    public function isStatusQuestion(string $message): bool
    {
        return preg_match(
            '/\b(?:(?:my|our)\s+(?:booking|appointment|schedule|pre-?registration|pet)|(?:booking|appointment|schedule|pet)\s+status|where\s+is\s+my\s+(?:pet|booking)|status\s+(?:ng|of)\s+(?:booking|appointment|alaga)|(?:booking|appointment|schedule)\s+ko|nasaan\s+(?:ang\s+)?alaga\s+ko)\b/iu',
            $message
        ) === 1;
    }

    public function answer(?User $user, string $message): string
    {
        if (! $user || $user->role !== 'customer') {
            return implode("\n\n", [
                '**Sign in** to your customer account so I can safely check your own schedule.',
                'You can also open the Dashboard and select "Schedules" or "Grooming Tracker".',
            ]);
        }

        $schedules = collect()
            ->merge($this->groomingSchedules($user, $message))
            ->merge($this->clinicSchedules($user, $message))
            ->sortByDesc('sort_date')
            ->take(3)
            ->values();

        if ($schedules->isEmpty()) {
            return implode("\n\n", [
                'I could not find an active schedule for your account.',
                'Open **"Schedules"** on the Dashboard to review completed or older records, or contact 7007-3122 / 0917-113-1941 for assistance.',
            ]);
        }

        return "**Your current schedules**\n\n"
            .$schedules->map(fn (array $schedule) => '- '.$schedule['line'])->implode("\n");
    }

    /**
     * @return Collection<int, array{sort_date: string, line: string}>
     */
    private function groomingSchedules(User $user, string $message): Collection
    {
        if (! Schema::hasTable('bookings')) {
            return collect();
        }

        $query = Booking::query()
            ->where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->with(['timeWindow', 'bookingPets.pet'])
            ->orderByDesc('booking_date')
            ->orderByDesc('booking_id')
            ->limit(5);

        $petName = $this->mentionedPetName($user, $message);
        if ($petName !== null) {
            $query->whereHas(
                'bookingPets.pet',
                fn ($petQuery) => $petQuery->where('pet_name', $petName)
            );
        }

        return $query->get()->map(function (Booking $booking) {
            $pets = $booking->bookingPets
                ->map(fn (BookingPet $bookingPet) => $bookingPet->pet?->pet_name)
                ->filter()
                ->implode(', ');
            $status = $this->groomingStatusLabel($booking);
            $date = Carbon::parse($booking->booking_date)->format('M j, Y');
            $window = $booking->timeWindow?->window_label;
            $when = $window ? "{$date}, {$window}" : $date;

            return [
                'sort_date' => (string) $booking->booking_date,
                'line' => 'Grooming '.$booking->booking_reference
                    .' — '.($pets ?: 'Pet').' — '.$status.' — '.$when,
            ];
        });
    }

    /**
     * @return Collection<int, array{sort_date: string, line: string}>
     */
    private function clinicSchedules(User $user, string $message): Collection
    {
        if (! Schema::hasTable('clinic_appointments')) {
            return collect();
        }

        $query = ClinicAppointment::query()
            ->where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled'])
            ->with(['timeWindow', 'pet'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id')
            ->limit(5);

        $petName = $this->mentionedPetName($user, $message);
        if ($petName !== null) {
            $query->whereHas('pet', fn ($petQuery) => $petQuery->where('pet_name', $petName));
        }

        return $query->get()->map(function (ClinicAppointment $appointment) {
            $date = $appointment->appointment_date->format('M j, Y');
            $window = $appointment->timeWindow?->window_label;
            $when = $window ? "{$date}, {$window}" : $date;

            return [
                'sort_date' => $appointment->appointment_date->toDateString(),
                'line' => 'Clinic '.$appointment->appointment_reference
                    .' — '.($appointment->pet?->pet_name ?: 'Pet')
                    .' — '.$this->statusLabel($appointment->status).' — '.$when,
            ];
        });
    }

    private function mentionedPetName(User $user, string $message): ?string
    {
        if (! Schema::hasTable('pets')) {
            return null;
        }

        return $user->pets()
            ->get(['pet_name'])
            ->first(fn ($pet) => str_contains(
                mb_strtolower($message),
                mb_strtolower($pet->pet_name)
            ))?->pet_name;
    }

    private function groomingStatusLabel(Booking $booking): string
    {
        $petStates = $booking->bookingPets
            ->map(fn (BookingPet $pet) => $pet->grooming_state)
            ->filter()
            ->unique();

        if ($petStates->count() === 1) {
            return $this->statusLabel((string) $petStates->first());
        }

        return $this->statusLabel($booking->status);
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'Waiting to arrive',
            'waiting', 'checked_in' => 'Checked in / waiting',
            'in_progress', 'in_consultation' => 'In progress',
            'paused' => 'Paused — clinic will contact you',
            'stopped' => 'Stopped — check your notifications',
            'grooming_finished', 'finished', 'groomed' => 'Grooming finished',
            'checked_in_for_pickup' => 'Ready for pickup',
            'waiting_for_payment', 'for_payment' => 'For payment',
            'released', 'completed' => 'Completed',
            'no_show' => 'No-show',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }
}
