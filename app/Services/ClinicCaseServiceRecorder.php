<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicCaseServiceRecorder
{
    public function save(int $appointmentId, array $data, User $actor, ?int $caseServiceId = null, bool $finalizingConfinement = false): ClinicCaseService
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
        $data = Validator::make($data, [
            'clinic_service_id' => $caseServiceId ? 'prohibited' : 'required|integer',
            'request_key' => $caseServiceId ? 'prohibited' : 'required|uuid',
            'status' => ['required', Rule::in(ClinicCaseService::STATUSES)],
            'quantity' => 'sometimes|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'notes' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($appointmentId, $data, $actor, $caseServiceId, $finalizingConfinement) {
            $appointment = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $entry = $caseServiceId
                ? $appointment->caseServices()->whereKey($caseServiceId)->firstOrFail()
                : ClinicCaseService::where('request_key', $data['request_key'])->first();
            if ($entry && ($entry->clinic_appointment_id !== $appointment->id
                || (! $caseServiceId && (int) $entry->clinic_service_id !== (int) $data['clinic_service_id']))) {
                throw ValidationException::withMessages(['request_key' => 'This service request has already been used.']);
            }
            // A delayed ordered request must not undo a completed transition.
            if ($entry && $entry->status !== 'ordered') {
                if ($entry->status === $data['status'] || (! $caseServiceId && $data['status'] === 'ordered')) {
                    return $entry->load('charge');
                }
                throw ValidationException::withMessages(['status' => 'Performed and cancelled services cannot be changed. Review the billing charge for financial corrections.']);
            }
            if ($entry && ! $finalizingConfinement && \Illuminate\Support\Facades\Schema::hasTable('clinic_confinements')
                && $entry->confinement()->exists()) {
                throw ValidationException::withMessages(['status' => 'Finalize the base confinement service through Discharge & Send to Billing.']);
            }
            if ($appointment->paid || $appointment->archived_at || ! $appointment->clinicalContentEditableFor($actor)) {
                throw ValidationException::withMessages(['status' => 'Clinical services cannot be changed at this case stage.']);
            }
            $service = ClinicService::with('category')->whereKey($entry?->clinic_service_id ?? $data['clinic_service_id'])
                ->lockForUpdate()->firstOrFail();
            if (! $service->is_active && $data['status'] !== 'cancelled') {
                throw ValidationException::withMessages(['clinic_service_id' => 'Choose an active Clinic service.']);
            }
            if (! $entry) {
                $entry = $appointment->caseServices()->make([
                    'clinic_service_id' => $service->id, 'request_key' => $data['request_key'],
                    'quantity' => $data['quantity'] ?? 1,
                ]);
                $entry->forceFill(['recorded_by_user_id' => $actor->user_id, 'ordered_at' => $data['status'] === 'ordered' ? now() : null]);
            }
            $entry->fill(array_intersect_key($data, array_flip(['quantity', 'notes'])));
            $entry->forceFill(['status' => $data['status']]);
            if ($data['status'] === 'performed') {
                $pricing = app(ClinicServicePriceResolver::class)->resolve($service, $appointment, $data['applied_price'] ?? null);
                $entry->forceFill([
                    'performed_at' => now(), 'performed_by_user_id' => $actor->user_id,
                    'applied_price' => $pricing['price'], 'service_snapshot' => $pricing['snapshot'],
                ]);
            } elseif ($data['status'] === 'cancelled') {
                $entry->forceFill(['cancelled_at' => now(), 'cancelled_by_user_id' => $actor->user_id]);
            }
            $entry->save();
            if ($entry->status === 'performed') {
                $charge = $appointment->charges()->make([
                    'kind' => 'service', 'clinic_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => $entry->quantity, 'unit_price' => $entry->applied_price,
                    'amount' => app(ClinicBillingService::class)->lineAmount($entry->quantity, $entry->applied_price),
                ]);
                $charge->forceFill(['clinic_case_service_id' => $entry->id])->save();
                app(ClinicBillingService::class)->updateTotal($appointment);
            }
            return $entry->load('charge');
        });
    }
}
