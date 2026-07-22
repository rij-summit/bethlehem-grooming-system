<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicAttachment;
use App\Models\ClinicRecord;
use App\Models\ClinicVital;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminClinicController extends Controller
{
    // ── Queue index ──────────────────────────────────────────────────────────

    public function index()
    {
        $with = ['user', 'walkin', 'pet', 'vitals', 'record'];

        $incoming = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'waiting_to_arrive')
            ->orderBy('queue_number')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $queued = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'checked_in')
            ->orderBy('queue_number')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $inConsultation = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'in_consultation')
            ->orderBy('consultation_started_at')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $forPayment = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'for_payment')
            ->orderBy('consultation_finished_at')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        $completed = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'completed')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn ($a) => $this->formatAppointment($a));

        return response()->json([
            'success' => true,
            'incoming' => $incoming,
            'queued' => $queued,
            'in_consultation' => $inConsultation,
            'for_payment' => $forPayment,
            'completed' => $completed,
        ]);
    }

    // ── Status transitions ───────────────────────────────────────────────────

    public function checkIn(int $id)
    {
        $appt = ClinicAppointment::findOrFail($id);

        if ($appt->status !== 'waiting_to_arrive') {
            return response()->json(['success' => false, 'message' => 'Appointment is not in Waiting to Arrive status.'], 422);
        }

        $appt->update([
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ]);

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'vitals', 'record']))]);
    }

    public function startConsultation(int $id)
    {
        $appt = ClinicAppointment::findOrFail($id);

        if ($appt->status !== 'checked_in') {
            return response()->json(['success' => false, 'message' => 'Appointment must be checked in first.'], 422);
        }

        $appt->update([
            'status' => 'in_consultation',
            'consultation_started_at' => now(),
        ]);

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'vitals', 'record']))]);
    }

    public function finishConsultation(int $id)
    {
        $appt = ClinicAppointment::findOrFail($id);

        if ($appt->status !== 'in_consultation') {
            return response()->json(['success' => false, 'message' => 'Appointment is not in consultation.'], 422);
        }

        $appt->update([
            'status' => 'for_payment',
            'consultation_finished_at' => now(),
        ]);

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'vitals', 'record']))]);
    }

    public function markPaid(Request $request, int $id)
    {
        $request->validate([
            'total_amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,gcash,maya,card'],
        ]);

        $appt = ClinicAppointment::findOrFail($id);

        if ($appt->status !== 'for_payment') {
            return response()->json(['success' => false, 'message' => 'Appointment is not For Payment.'], 422);
        }

        $appt->update([
            'status' => 'completed',
            'total_amount' => $request->total_amount,
            'paid' => true,
        ]);

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'vitals', 'record']))]);
    }

    public function cancel(Request $request, int $id)
    {
        $appt = ClinicAppointment::findOrFail($id);

        if (in_array($appt->status, ['completed', 'cancelled'])) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel a completed or already-cancelled appointment.'], 422);
        }

        $appt->update(['status' => 'cancelled']);

        return response()->json(['success' => true]);
    }

    // ── Medical record ───────────────────────────────────────────────────────

    public function saveRecord(Request $request, int $id)
    {
        $request->validate([
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'treatment_given' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_notes' => ['nullable', 'string', 'max:1000'],
            'vet_notes' => ['nullable', 'string', 'max:2000'],

            // Vitals
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'temperature_c' => ['nullable', 'numeric', 'min:0'],
            'heart_rate_bpm' => ['nullable', 'integer', 'min:0'],
            'respiratory_rate_bpm' => ['nullable', 'integer', 'min:0'],
            'body_condition_score' => ['nullable', 'integer', 'min:1', 'max:9'],

            // Medications
            'medications' => ['nullable', 'array'],
            'medications.*.drug_name' => ['required', 'string', 'max:200'],
            'medications.*.dosage' => ['nullable', 'string', 'max:100'],
            'medications.*.frequency' => ['nullable', 'string', 'max:100'],
            'medications.*.duration' => ['nullable', 'string', 'max:100'],
            'medications.*.instructions' => ['nullable', 'string', 'max:500'],
        ]);

        $appt = ClinicAppointment::findOrFail($id);

        return DB::transaction(function () use ($request, $appt) {
            // Upsert medical record
            $record = ClinicRecord::updateOrCreate(
                ['clinic_appointment_id' => $appt->id],
                [
                    'chief_complaint' => $request->chief_complaint ?? $appt->chief_complaint,
                    'diagnosis' => $request->diagnosis,
                    'findings' => $request->findings,
                    'treatment_given' => $request->treatment_given,
                    'follow_up_date' => $request->follow_up_date,
                    'follow_up_notes' => $request->follow_up_notes,
                    'vet_notes' => $request->vet_notes,
                ]
            );

            // Upsert vitals
            if ($request->hasAny(['weight_kg', 'temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm', 'body_condition_score'])) {
                ClinicVital::updateOrCreate(
                    ['clinic_appointment_id' => $appt->id],
                    [
                        'weight_kg' => $request->weight_kg,
                        'temperature_c' => $request->temperature_c,
                        'heart_rate_bpm' => $request->heart_rate_bpm,
                        'respiratory_rate_bpm' => $request->respiratory_rate_bpm,
                        'body_condition_score' => $request->body_condition_score,
                    ]
                );
            }

            // Replace medications
            if ($request->has('medications')) {
                $record->medications()->delete();
                foreach ($request->medications as $med) {
                    $record->medications()->create($med);
                }
            }

            $record->load(['medications', 'attachments']);

            return response()->json([
                'success' => true,
                'record' => $this->formatRecord($record, $appt->id),
            ]);
        });
    }

    // ── File upload ──────────────────────────────────────────────────────────

    public function uploadAttachment(Request $request, int $id)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,dcm'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        $appt = ClinicAppointment::findOrFail($id);
        $record = ClinicRecord::firstOrCreate(
            ['clinic_appointment_id' => $appt->id],
            ['chief_complaint' => $appt->chief_complaint]
        );

        $file = $request->file('file');
        $path = $file->store("clinic/attachments/{$appt->id}", 'local');

        if (! $path) {
            return response()->json([
                'success' => false,
                'message' => 'The attachment could not be stored.',
            ], 500);
        }

        try {
            $attachment = $record->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getMimeType(),
                'file_size_bytes' => $file->getSize(),
                'label' => $request->label,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'attachment' => $this->formatAttachment($attachment, $appt->id),
        ]);
    }

    public function downloadAttachment(int $id, int $attachmentId)
    {
        $attachment = $this->findScopedAttachment($id, $attachmentId);
        $disk = $this->attachmentDisk($attachment->file_path);

        if ($disk === null) {
            $this->attachmentNotFound();
        }

        return Storage::disk($disk)->download(
            $attachment->file_path,
            $this->safeDownloadName($attachment),
            [
                'Content-Type' => $this->safeMimeType($attachment->file_type),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function deleteAttachment(int $id, int $attachmentId)
    {
        $attachment = $this->findScopedAttachment($id, $attachmentId);
        $disk = $this->attachmentDisk($attachment->file_path);

        if ($disk !== null && ! Storage::disk($disk)->delete($attachment->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'The attachment could not be deleted.',
            ], 500);
        }

        $attachment->delete();

        return response()->json(['success' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatAppointment(ClinicAppointment $a): array
    {
        $user = $a->user;
        $walkin = $a->walkin;
        $pet = $a->pet;
        $vitals = $a->vitals;
        $record = $a->record;

        $ownerName = $user
            ? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
            : ($walkin ? trim("{$walkin->fname} {$walkin->lname}") : '—');

        $contactNumber = $user?->phone ?? $walkin?->phone ?? '—';

        return [
            'id' => $a->id,
            'appointment_reference' => $a->appointment_reference,
            'appointment_type' => $a->appointment_type,
            'status' => $a->status,
            'queue_number' => $a->queue_number,
            'appointment_date' => $a->appointment_date?->toDateString(),
            'chief_complaint' => $a->chief_complaint,
            'total_amount' => $a->total_amount,
            'paid' => $a->paid,
            'notes' => $a->notes,
            'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            'consultation_started_at' => $a->consultation_started_at?->toIso8601String(),
            'consultation_finished_at' => $a->consultation_finished_at?->toIso8601String(),
            'ownerName' => $ownerName,
            'contactNumber' => $contactNumber,
            'isWalkin' => $walkin !== null,
            'pet' => $pet ? [
                'id' => $pet->pet_id,
                'name' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'weight' => $pet->weight,
            ] : null,
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
            'record' => $this->formatRecord($record, $a->id),
        ];
    }

    private function formatRecord(?ClinicRecord $record, int $appointmentId): ?array
    {
        if (! $record) {
            return null;
        }

        $record->loadMissing(['medications', 'attachments']);

        return [
            'id' => $record->id,
            'chief_complaint' => $record->chief_complaint,
            'diagnosis' => $record->diagnosis,
            'findings' => $record->findings,
            'treatment_given' => $record->treatment_given,
            'follow_up_date' => $record->follow_up_date?->toDateString(),
            'follow_up_notes' => $record->follow_up_notes,
            'vet_notes' => $record->vet_notes,
            'medications' => $record->medications->map(fn ($medication) => [
                'id' => $medication->id,
                'drug_name' => $medication->drug_name,
                'dosage' => $medication->dosage,
                'frequency' => $medication->frequency,
                'duration' => $medication->duration,
                'instructions' => $medication->instructions,
            ])->values(),
            'attachments' => $record->attachments
                ->map(fn (ClinicAttachment $attachment) => $this->formatAttachment($attachment, $appointmentId))
                ->values(),
        ];
    }

    private function formatAttachment(ClinicAttachment $attachment, int $appointmentId): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size_bytes' => $attachment->file_size_bytes,
            'label' => $attachment->label,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'download_endpoint' => "/api/admin/clinic-appointments/{$appointmentId}/attachments/{$attachment->id}/download",
        ];
    }

    private function findScopedAttachment(int $appointmentId, int $attachmentId): ClinicAttachment
    {
        $appointmentExists = ClinicAppointment::whereKey($appointmentId)->exists();

        if (! $appointmentExists) {
            $this->attachmentNotFound();
        }

        $attachment = ClinicAttachment::query()
            ->whereKey($attachmentId)
            ->whereHas('record', fn ($query) => $query->where('clinic_appointment_id', $appointmentId))
            ->first();

        if (! $attachment) {
            $this->attachmentNotFound();
        }

        return $attachment;
    }

    private function attachmentDisk(string $path): ?string
    {
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    private function safeDownloadName(ClinicAttachment $attachment): string
    {
        $fileName = basename(str_replace('\\', '/', $attachment->file_name));
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?? '';

        return $fileName !== '' ? $fileName : "clinic-attachment-{$attachment->id}";
    }

    private function safeMimeType(?string $mimeType): string
    {
        return $mimeType && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mimeType)
            ? $mimeType
            : 'application/octet-stream';
    }

    private function attachmentNotFound(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Attachment not found.',
        ], 404));
    }
}
