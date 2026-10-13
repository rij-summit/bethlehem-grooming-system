<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicConfinement;
use App\Models\ClinicConfinementObservation;
use App\Models\ClinicService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClinicConfinementService
{
    public const CATEGORY = 'Boarding & Lodging';
    public const BILLING_UNITS = ['service', 'visit', 'pet', 'day'];

    public function admit(int $appointmentId, array $data, User $actor): ClinicConfinement
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'clinic_service_id' => 'required|integer', 'request_key' => 'required|uuid',
            'reason' => 'required|string|max:2000', 'notes' => 'nullable|string|max:5000',
            'admitted_at' => 'prohibited', 'attending_veterinarian_id' => 'prohibited',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $existing = $case->confinement()->with('caseService')->first();
            if ($existing) {
                if ($existing->caseService->request_key === $data['request_key']
                    && (int) $existing->caseService->clinic_service_id === (int) $data['clinic_service_id']) return $existing->load('caseService.clinicService.category');
                throw ValidationException::withMessages(['confinement' => 'This case already has a confinement episode. Open its existing history.']);
            }
            $this->assertOngoing($case);
            $record = $case->record;
            if (! $record?->primary_veterinarian_id) {
                throw ValidationException::withMessages(['primary_veterinarian_id' => 'Complete the Primary Attending Veterinarian in the Medical Record before admission.']);
            }
            $service = ClinicService::with('category')->whereKey($data['clinic_service_id'])->lockForUpdate()->firstOrFail();
            $this->assertService($service);
            $entry = app(ClinicCaseServiceRecorder::class)->save($case->id, [
                'clinic_service_id' => $service->id, 'request_key' => $data['request_key'], 'status' => 'ordered', 'quantity' => 1,
            ], $actor);
            // A retry key for an unrelated pre-existing occurrence is not an admission.
            if (! $entry->wasRecentlyCreated) {
                throw ValidationException::withMessages(['request_key' => 'Use a new request key for this confinement admission.']);
            }
            return $case->confinement()->create([
                'clinic_case_service_id' => $entry->id, 'admitted_at' => now(),
                'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
                'attending_veterinarian_id' => $record->primary_veterinarian_id,
                'attending_veterinarian_name' => $record->primary_veterinarian_name,
                'admitted_by_user_id' => $actor->user_id,
            ])->load('caseService.clinicService.category');
        });
    }

    public function recordObservation(int $appointmentId, array $data, User $actor): ClinicConfinementObservation
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'request_key' => 'required|uuid', 'temperature_c' => 'nullable|numeric|decimal:0,1|min:0|max:999.9',
            'heart_rate_bpm' => 'nullable|integer|min:0|max:65535', 'respiratory_rate_bpm' => 'nullable|integer|min:0|max:65535',
            'notes' => 'nullable|string|max:5000', 'recorded_at' => 'prohibited',
        ])->validate();
        if (collect($data)->except('request_key')->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isEmpty()) {
            throw ValidationException::withMessages(['observation' => 'Record at least one clinical value or observation.']);
        }
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            $existing = ClinicConfinementObservation::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->clinic_confinement_id !== $episode->id) {
                    throw ValidationException::withMessages(['request_key' => 'This monitoring request has already been used.']);
                }
                return $existing;
            }
            $this->assertOngoing($case);
            if ($episode->discharged_at) throw ValidationException::withMessages(['confinement' => 'This confinement has already been discharged.']);
            return $episode->observations()->create([...$data, 'recorded_at' => now(), 'recorded_by_user_id' => $actor->user_id]);
        });
    }

    public function dischargePreview(ClinicAppointment $case): array
    {
        $episode = $case->confinement()->with('caseService.clinicService.category')->firstOrFail();
        $this->assertOngoing($case);
        if ($episode->discharged_at) throw ValidationException::withMessages(['confinement' => 'This confinement has already been discharged.']);
        $service = $episode->caseService->clinicService;
        $this->assertService($service);
        $end = now();
        return [
            'data' => $episode, 'service' => $service,
            'pricing' => app(ClinicServicePriceResolver::class)->preview($service, $case),
            'quantity' => $this->quantity($episode, $service, $end),
            'duration_seconds' => max(0, $end->getTimestamp() - $episode->admitted_at->getTimestamp()),
        ];
    }

    public function discharge(int $appointmentId, array $data, User $actor): ClinicConfinement
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'discharge_note' => 'nullable|string|max:5000', 'quantity' => 'prohibited',
            'billable_days' => 'prohibited', 'discharged_at' => 'prohibited',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            if ($episode->discharged_at) return $episode->load('caseService.charge');
            $this->assertOngoing($case);
            $entry = $episode->caseService;
            if ($entry->status !== 'ordered') {
                throw ValidationException::withMessages(['confinement' => 'The confinement service is no longer ordered. Review the clinical history.']);
            }
            $service = ClinicService::with('category')->whereKey($entry->clinic_service_id)->lockForUpdate()->firstOrFail();
            $this->assertService($service);
            $end = now();
            $case->assertCompletionVitals();
            app(ClinicCaseServiceRecorder::class)->save($case->id, [
                'status' => 'performed', 'quantity' => $this->quantity($episode, $service, $end),
                'applied_price' => $data['applied_price'] ?? null,
            ], $actor, $entry->id, finalizingConfinement: true);
            $episode->update(['discharged_at' => $end, 'discharged_by_user_id' => $actor->user_id,
                'discharge_note' => $data['discharge_note'] ?? null]);
            $case->prepareForPayment();
            return $episode->load('caseService.charge');
        });
    }

    private function quantity(ClinicConfinement $episode, ClinicService $service, \Carbon\CarbonInterface $end): int
    {
        return $service->billing_unit === 'day'
            ? app(ConfinementBillingDuration::class)->billableDays($episode->admitted_at, $end) : 1;
    }

    private function assertOngoing(ClinicAppointment $case): void
    {
        if ($case->status !== 'in_consultation' || $case->paid || $case->archived_at) {
            throw ValidationException::withMessages(['status' => 'Confinement actions require an ongoing, unpaid and unarchived case in consultation.']);
        }
    }

    private function assertService(ClinicService $service): void
    {
        if (! $service->is_active || $service->category?->name !== self::CATEGORY
            || ! in_array($service->billing_unit, [null, ...self::BILLING_UNITS], true)) {
            throw ValidationException::withMessages(['clinic_service_id' => 'Choose an active Boarding & Lodging service with a per-service, visit, pet or day billing unit in the Service Catalogue.']);
        }
    }

    private function assertClinical(User $actor): void
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
    }
}
