<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PetProfileHistoryService
{
    public function groomingRecords(Pet $pet): Collection
    {
        $hasCancellationAuditColumns = Schema::hasColumns('bookings', [
            'cancel_count',
            'cancellation_reason',
        ]);

        return Booking::query()
            ->neverCancelled($hasCancellationAuditColumns)
            ->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $pet->pet_id))
            ->with([
                'timeWindow',
                'bookingPets' => fn ($query) => $query->where('pet_id', $pet->pet_id),
                'bookingServices.service',
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('booking_id')
            ->get()
            ->map(function (Booking $booking) {
                $bookingPet = $booking->bookingPets->first();

                if (! $bookingPet) {
                    return null;
                }

                $services = $booking->bookingServices
                    ->where('booking_pet_id', $bookingPet->booking_pet_id)
                    ->map(fn ($bookingService) => $bookingService->service?->service_name)
                    ->filter()
                    ->values();

                return [
                    'booking_reference' => $booking->booking_reference,
                    'booking_date' => $booking->booking_date,
                    'arrival_window' => $booking->timeWindow?->window_label,
                    'status' => $this->groomingStatus($booking, $bookingPet),
                    'grooming_started_at' => $bookingPet->grooming_start_time?->format('g:i A'),
                    'grooming_finished_at' => $bookingPet->grooming_end_time?->format('g:i A'),
                    'services' => $services,
                    'paid' => (bool) $booking->paid,
                ];
            })
            ->filter()
            ->values();
    }

    public function medicalRecords(Pet $pet): Collection
    {
        return ClinicAppointment::query()
            ->select([
                'id',
                'appointment_reference',
                'appointment_date',
                'status',
                'pet_id',
                'chief_complaint',
            ])
            ->where('pet_id', $pet->pet_id)
            ->where('status', 'completed')
            ->whereHas('record')
            ->with([
                'vitals:id,clinic_appointment_id,weight_kg,temperature_c,heart_rate_bpm,respiratory_rate_bpm,body_condition_score',
                'record:id,clinic_appointment_id,chief_complaint,diagnosis,treatment_given,follow_up_date,follow_up_notes',
                'record.medications:id,clinic_record_id,drug_name,dosage,frequency,duration,instructions',
            ])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClinicAppointment $appointment) => $this->formatMedicalRecord($appointment))
            ->values();
    }

    public function vaccinationRecords(Pet $pet): Collection
    {
        return $pet->vaccinationRecords()
            ->visibleToClients()
            ->with([
                'clinicAppointment:id,appointment_reference',
                'administeredBy:user_id,first_name,last_name',
            ])
            ->orderByDesc('administered_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VaccinationRecord $record) => $this->formatVaccinationRecord($record))
            ->values();
    }

    private function groomingStatus(Booking $booking, BookingPet $bookingPet): string
    {
        if (in_array($booking->status, ['cancelled', 'no_show'], true)) {
            return $booking->status;
        }

        return match (true) {
            $bookingPet->grooming_end_time !== null => 'grooming_finished',
            $bookingPet->grooming_start_time !== null => 'in_progress',
            default => $booking->status,
        };
    }

    private function formatMedicalRecord(ClinicAppointment $appointment): array
    {
        $record = $appointment->record;
        $vitals = $appointment->vitals;

        return [
            'appointment_reference' => $appointment->appointment_reference,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'status' => $appointment->status,
            'chief_complaint' => $record->chief_complaint ?? $appointment->chief_complaint,
            'diagnosis' => $record->diagnosis,
            'treatment_given' => $record->treatment_given,
            'follow_up_date' => $record->follow_up_date?->toDateString(),
            'follow_up_notes' => $record->follow_up_notes,
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
            'medications' => $record->medications->map(fn ($medication) => [
                'drug_name' => $medication->drug_name,
                'dosage' => $medication->dosage,
                'frequency' => $medication->frequency,
                'duration' => $medication->duration,
                'instructions' => $medication->instructions,
            ])->values(),
        ];
    }

    private function formatVaccinationRecord(VaccinationRecord $record): array
    {
        return [
            'vaccine_name' => $record->vaccine_name,
            'product_name' => $record->product_name,
            'manufacturer' => $record->manufacturer,
            'batch_number' => $record->batch_number,
            'administered_date' => $record->administered_date?->toDateString(),
            'next_due_date' => $record->next_due_date?->toDateString(),
            'product_expiry_date' => $record->product_expiry_date?->toDateString(),
            'dose_amount' => $record->dose_amount,
            'dose_unit' => $record->dose_unit,
            'route' => $record->route,
            'administration_site' => $record->administration_site,
            'administering_provider' => $this->providerDisplay($record),
            'appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'due_status' => $record->dueStatus(),
        ];
    }

    private function providerDisplay(VaccinationRecord $record): string
    {
        if (filled($record->administered_by_name)) {
            return $record->administered_by_name;
        }

        $provider = $this->formatUserName($record->administeredBy);

        return $provider ?? 'Not provided.';
    }

    private function formatUserName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
