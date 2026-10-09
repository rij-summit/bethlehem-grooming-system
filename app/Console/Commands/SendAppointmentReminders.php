<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Services\PreRegistrationExpiry;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'reminders:send';
    protected $description = 'Send preferred-arrival reminders for Clinic and Grooming pre-registrations.';

    public function handle(PreRegistrationExpiry $expiry): void
    {
        $expiry->expire();
        Booking::where('status', 'waiting_to_arrive')->whereNotNull('window_id')
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->chunkById(100, function ($bookings) {
                foreach ($bookings as $booking) {
                    $this->remind($booking, 'grooming', $booking->booking_date, $this->petNames($booking));
                }
            }, 'booking_id');
        ClinicAppointment::where('status', 'waiting_to_arrive')->whereNotNull('window_id')
            ->with(['user', 'timeWindow', 'pet'])
            ->chunkById(100, function ($visits) {
                foreach ($visits as $visit) {
                    $this->remind($visit, 'clinic', $visit->appointment_date->toDateString(), $visit->pet?->pet_name ?? 'your pet');
                }
            });
        $this->info('Preferred-arrival reminders processed.');
    }

    private function remind(Booking|ClinicAppointment $registration, string $service, string $date, string $pets): void
    {
        if (! $registration->timeWindow || ! $registration->user) {
            return;
        }
        $arrival = Carbon::parse($date.' '.$registration->timeWindow->start_time);
        $minutes = now()->diffInMinutes($arrival, false);
        $period = match (true) {
            $minutes >= 1380 && $minutes <= 1500 => '24h',
            $minutes >= 150 && $minutes <= 210 => '3h',
            default => null,
        };
        if ($period === null) {
            return;
        }

        $foreignKey = $service === 'clinic' ? 'clinic_appointment_id' : 'booking_id';
        $type = ($service === 'clinic' ? 'clinic_' : '').'reminder_'.$period;
        $window = $registration->timeWindow->displayLabel();
        $when = $period === '24h' ? 'tomorrow' : 'today';
        $plan = $service === 'clinic' ? 'clinic arrival' : 'grooming drop-off';
        CustomerNotification::firstOrCreate([
            'user_id' => $registration->user->user_id,
            $foreignKey => $registration->getKey(),
            'type' => $type,
        ], [
            'message' => "Reminder: You planned {$pets}'s {$plan} {$when} around {$window}. Your pet joins the queue after check-in.",
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    private function petNames(Booking $booking): string
    {
        $names = $booking->bookingPets->map(fn ($pet) => $pet->pet?->pet_name)
            ->filter()->unique()->values();

        return $names->isEmpty() ? 'your pet' : $names->join(', ', ' and ');
    }
}
