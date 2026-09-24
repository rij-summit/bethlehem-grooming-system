<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicAttachment;
use App\Models\ClinicRecord;
use App\Models\ClinicVital;
use App\Models\Notification;
use App\Models\Pet;
use App\Services\ClinicAppointmentSequence;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminClinicController extends Controller
{
    private const TERMINAL_CASE_STATUSES = ['completed', 'cancelled', 'no_show'];
    private const CLINICAL_CONTENT_EDITABLE_STATUSES = [
        'checked_in',
        'in_consultation',
        'for_payment',
    ];

    // ── Queue index ──────────────────────────────────────────────────────────

    public function index()
    {
        $with = $this->appointmentRelations();

        $incoming = ClinicAppointment::with($with)
            ->where('appointment_date', now()->toDateString())
            ->where('status', 'waiting_to_arrive')
            ->orderBy('created_at')
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

    /** Paginated, newest-first patient directory for the Clinic Records landing view. */
    public function records(Request $request)
    {
        $perPage = 25;
        $page = Pet::query()
            ->with(['user:user_id,first_name,last_name,phone,email', 'unregisteredCustomer:id,first_name,last_name,phone,email'])
            ->where('is_archived', false)
            ->where(fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_archived', false))
                ->orWhereHas('unregisteredCustomer', fn ($u) => $u->where('is_archived', false)))
            ->orderByDesc('pet_id')
            ->simplePaginate($perPage);

        $rows = $page->getCollection()->map(function (Pet $pet) {
            $owner = $pet->user ?? $pet->unregisteredCustomer;
            return [
                'owner' => [
                    'id' => $pet->user ? $pet->user->user_id : $owner?->id,
                    'recordType' => $pet->user ? 'registered' : 'unregistered',
                    'fullName' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                    'phone' => $owner?->phone,
                    'email' => $owner?->email,
                ],
                'pet' => [
                    'id' => $pet->pet_id,
                    'petName' => $pet->pet_name,
                    'species' => $pet->species,
                    'breed' => $pet->breed,
                ],
            ];
        })->values();

        return response()->json(['success' => true, 'rows' => $rows, 'has_more' => $page->hasMorePages()]);
    }

    /** Lightweight, case-oriented replacement for the old queue response. */
    public function activeCases()
    {
        $cases = ClinicAppointment::query()
            ->with($this->appointmentRelations())
            ->whereNotIn('status', self::TERMINAL_CASE_STATUSES)
            ->orderByRaw("CASE WHEN status = 'waiting_to_arrive' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ClinicAppointment $case) => $this->formatAppointment($case))
            ->values();

        return response()->json(['success' => true, 'cases' => $cases]);
    }

    public function createCase(Request $request, ClinicAppointmentSequence $sequence)
    {
        $data = $request->validate([
            'pet_id' => ['required', 'integer', 'exists:pets,pet_id'],
            'case_type' => ['required', 'in:consultation,vaccination'],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($data, $sequence) {
            $pet = Pet::query()->whereKey($data['pet_id'])->where('is_archived', false)->firstOrFail();
            $existing = ClinicAppointment::query()
                ->where('pet_id', $pet->pet_id)
                ->whereNotIn('status', self::TERMINAL_CASE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === 'waiting_to_arrive') {
                    $existing->update(['status' => 'in_consultation', 'queue_number' => null, 'consultation_started_at' => now()]);
                }

                return response()->json(['success' => true, 'created' => false, 'case' => $this->formatAppointment($existing)]);
            }

            $reserved = $sequence->reserve(now()->toDateString(), false);
            $case = ClinicAppointment::create([
                'appointment_reference' => $reserved['appointment_reference'],
                'appointment_type' => 'walk_in',
                'case_type' => $data['case_type'],
                'status' => 'in_consultation',
                'queue_number' => null,
                'appointment_date' => now()->toDateString(),
                'user_id' => $pet->user_id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'total_amount' => 0,
                'paid' => false,
                'consultation_started_at' => now(),
            ]);

            return response()->json(['success' => true, 'created' => true, 'case' => $this->formatAppointment($case)], 201);
        });
    }

    public function startCase(int $id)
    {
        $case = ClinicAppointment::query()->whereKey($id)->firstOrFail();
        if ($case->status === 'waiting_to_arrive') {
            $case->update(['status' => 'in_consultation', 'queue_number' => null, 'consultation_started_at' => now()]);
        }
        if (in_array($case->status, self::TERMINAL_CASE_STATUSES, true)) {
            return response()->json(['success' => false, 'message' => 'This case is already closed.'], 409);
        }
        return response()->json(['success' => true, 'case' => $this->formatAppointment($case->fresh())]);
    }

    public function finishCase(int $id)
    {
        $case = DB::transaction(function () use ($id) {
            $appointment = ClinicAppointment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($appointment->status, self::TERMINAL_CASE_STATUSES, true)) {
                throw new HttpResponseException(response()->json(['success' => false, 'message' => 'This case is already closed.'], 409));
            }
            $appointment->update([
                'status' => 'completed',
                'queue_number' => null,
                'consultation_finished_at' => now(),
            ]);
            return $appointment;
        });
        return response()->json(['success' => true, 'case' => $this->formatAppointment($case->fresh())]);
    }

    public function archivedIndex(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $date = trim((string) $request->query('date', ''));

        $query = ClinicAppointment::with($this->appointmentRelations())
            ->whereIn('status', ['completed', 'cancelled', 'no_show']);

        if ($date !== '') {
            $query->whereDate('appointment_date', $date);
        }

        if ($search !== '') {
            $query->where(function ($archiveQuery) use ($search) {
                $like = "%{$search}%";

                $archiveQuery
                    ->where('appointment_reference', 'like', $like)
                    ->orWhere('chief_complaint', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('walkin', function ($walkinQuery) use ($like) {
                        $walkinQuery
                            ->where('fname', 'like', $like)
                            ->orWhere('lname', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('pet', function ($petQuery) use ($like) {
                        $petQuery
                            ->where('pet_name', 'like', $like)
                            ->orWhere('species', 'like', $like)
                            ->orWhere('breed', 'like', $like);
                    });
            });
        }

        $archived = $query
            ->orderByDesc('appointment_date')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ClinicAppointment $appointment) => $this->formatAppointment($appointment))
            ->values();

        return response()->json([
            'success' => true,
            'archived' => $archived,
            'total' => $archived->count(),
        ]);
    }

    // ── Status transitions ───────────────────────────────────────────────────

    public function checkIn(int $id, ClinicAppointmentSequence $clinicSequence)
    {
        $appt = DB::transaction(function () use ($id, $clinicSequence) {
            $appointment = ClinicAppointment::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'waiting_to_arrive') {
                return null;
            }

            $queueNumber = $appointment->queue_number;

            if (! $queueNumber) {
                $queueNumber = $clinicSequence->nextQueueNumber(
                    $appointment->appointment_date->toDateString(),
                );
            }

            $appointment->update([
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'checked_in_at' => now(),
            ]);

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Appointment is not in Waiting to Arrive status.'], 422);
        }

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'timeWindow', 'vitals', 'record']))]);
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

        return response()->json([
            'success' => true,
            'message' => 'Clinic consultation started.',
            'appointment' => $this->formatAppointment($appt->fresh($this->appointmentRelations())),
        ]);
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

        Notification::createForClinic(
            $appt,
            Notification::TYPE_CLINIC_PAYMENT_DUE,
            "Clinic visit {$appt->appointment_reference} is ready for payment.",
        );

        return response()->json([
            'success' => true,
            'message' => 'Clinic consultation finished.',
            'appointment' => $this->formatAppointment($appt->fresh($this->appointmentRelations())),
        ]);
    }

    public function markPaid(Request $request, int $id)
    {
        $request->validate([
            'total_amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,gcash,maya,card'],
        ]);

        $appt = DB::transaction(function () use ($id, $request) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($appointment->status !== 'for_payment') {
                return null;
            }

            $appointment->update([
                'status' => 'completed',
                'total_amount' => $request->total_amount,
                'paid' => true,
            ]);
            Notification::createForClinic(
                $appointment,
                Notification::TYPE_CLINIC_PAYMENT_CONFIRMED,
                "Payment received for clinic appointment {$appointment->appointment_reference}.",
            );

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Appointment is not For Payment.'], 422);
        }

        return response()->json(['success' => true, 'appointment' => $this->formatAppointment($appt->fresh(['user', 'walkin', 'pet', 'timeWindow', 'vitals', 'record']))]);
    }

    public function cancel(Request $request, int $id)
    {
        $appt = DB::transaction(function () use ($id) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
                return null;
            }

            $appointment->update(['status' => 'cancelled']);
            Notification::createForClinic(
                $appointment,
                Notification::TYPE_CLINIC_CANCELLED,
                "Clinic appointment {$appointment->appointment_reference} was cancelled by clinic staff.",
            );

            return $appointment;
        });

        if (! $appt) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel a completed or already-cancelled appointment.'], 422);
        }

        return response()->json(['success' => true]);
    }

    // ── Medical record ───────────────────────────────────────────────────────

    public function saveRecord(Request $request, int $id)
    {
        $data = $request->validate([
            'chief_complaint' => ['nullable', 'string', 'max:1200'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'treatment_given' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_notes' => ['nullable', 'string', 'max:1000'],
            'vet_notes' => ['nullable', 'string', 'max:2000'],
            'finish_case' => ['sometimes', 'boolean'],

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

        return DB::transaction(function () use ($data, $id) {
            $appt = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertClinicalContentEditable($appt);

            // Upsert medical record
            $record = ClinicRecord::updateOrCreate(
                ['clinic_appointment_id' => $appt->id],
                [
                    'chief_complaint' => $data['chief_complaint'] ?? $appt->chief_complaint,
                    'diagnosis' => $data['diagnosis'] ?? null,
                    'findings' => $data['findings'] ?? null,
                    'treatment_given' => $data['treatment_given'] ?? null,
                    'follow_up_date' => $data['follow_up_date'] ?? null,
                    'follow_up_notes' => $data['follow_up_notes'] ?? null,
                    'vet_notes' => $data['vet_notes'] ?? null,
                ]
            );

            // Upsert vitals
            if (collect([
                'weight_kg',
                'temperature_c',
                'heart_rate_bpm',
                'respiratory_rate_bpm',
                'body_condition_score',
            ])->contains(fn (string $field) => array_key_exists($field, $data))) {
                ClinicVital::updateOrCreate(
                    ['clinic_appointment_id' => $appt->id],
                    [
                        'weight_kg' => $data['weight_kg'] ?? null,
                        'temperature_c' => $data['temperature_c'] ?? null,
                        'heart_rate_bpm' => $data['heart_rate_bpm'] ?? null,
                        'respiratory_rate_bpm' => $data['respiratory_rate_bpm'] ?? null,
                        'body_condition_score' => $data['body_condition_score'] ?? null,
                    ]
                );
            }

            // Replace medications
            if (array_key_exists('medications', $data)) {
                $record->medications()->delete();
                foreach ($data['medications'] ?? [] as $med) {
                    $record->medications()->create($med);
                }
            }

            if (! empty($data['finish_case'])) {
                $appt->update([
                    'status' => 'completed',
                    'queue_number' => null,
                    'consultation_finished_at' => now(),
                ]);
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
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,dcm'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        return DB::transaction(function () use ($data, $id) {
            $appt = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertClinicalContentEditable($appt);

            $record = ClinicRecord::firstOrCreate(
                ['clinic_appointment_id' => $appt->id],
                ['chief_complaint' => $appt->chief_complaint]
            );

            $file = $data['file'];
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
                    'label' => $data['label'] ?? null,
                ]);
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($path);

                throw $exception;
            }

            return response()->json([
                'success' => true,
                'attachment' => $this->formatAttachment($attachment, $appt->id),
            ]);
        });
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
        return DB::transaction(function () use ($attachmentId, $id) {
            $appointment = ClinicAppointment::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
            if (! $appointment) {
                $this->attachmentNotFound();
            }
            $this->assertClinicalContentEditable($appointment);

            $attachment = ClinicAttachment::query()
                ->whereKey($attachmentId)
                ->whereHas(
                    'record',
                    fn ($query) => $query->where('clinic_appointment_id', $id),
                )
                ->lockForUpdate()
                ->first();
            if (! $attachment) {
                $this->attachmentNotFound();
            }

            $disk = $this->attachmentDisk($attachment->file_path);
            if ($disk !== null && ! Storage::disk($disk)->delete($attachment->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The attachment could not be deleted.',
                ], 500);
            }

            $attachment->delete();

            return response()->json(['success' => true]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatAppointment(ClinicAppointment $a): array
    {
        $a->loadMissing($this->appointmentRelations());
        $user = $a->user;
        $walkin = $a->walkin;
        $pet = $a->pet;
        $vitals = Schema::hasTable('clinic_vitals') ? $a->vitals : null;
        $record = Schema::hasTable('clinic_records') ? $a->record : null;
        $timeWindow = Schema::hasTable('time_windows') ? $a->timeWindow : null;

        $petOwner = $pet?->unregisteredCustomer;
        $ownerName = $user
            ? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
            : ($walkin ? trim("{$walkin->fname} {$walkin->lname}") : ($petOwner ? trim("{$petOwner->first_name} {$petOwner->last_name}") : '—'));

        $ownerAccountDeleted = $this->ownerAccountDeleted($a);
        $contactNumber = $ownerAccountDeleted
            ? '—'
            : ($user?->phone ?? $walkin?->phone ?? $petOwner?->phone ?? '—');
        $ownerEmail = $ownerAccountDeleted ? null : ($user?->email ?? $walkin?->email ?? $petOwner?->email);
        $appointmentTypeLabel = match ($a->appointment_type) {
            'pre_registered' => 'Pre-Registered',
            'scheduled' => 'Scheduled',
            default => 'Walk-in',
        };

        return [
            'id' => $a->id,
            'appointment_reference' => $a->appointment_reference,
            'appointment_type' => $a->appointment_type,
            'case_type' => $a->case_type ?? ($a->appointment_type === 'pre_registered' ? 'online_request' : 'consultation'),
            'status' => $a->status,
            'queue_number' => $a->queue_number,
            'appointment_date' => $a->appointment_date?->toDateString(),
            'time_window' => $timeWindow ? [
                'window_id' => $timeWindow->window_id,
                'window_label' => $timeWindow->displayLabel(),
                'start_time' => $timeWindow->start_time,
                'end_time' => $timeWindow->end_time,
            ] : null,
            'chief_complaint' => $a->chief_complaint,
            'common_concerns' => $a->common_concerns,
            'total_amount' => $a->total_amount,
            'paid' => $a->paid,
            'notes' => $a->notes,
            'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            'consultation_started_at' => $a->consultation_started_at?->toIso8601String(),
            'consultation_finished_at' => $a->consultation_finished_at?->toIso8601String(),
            'archived_at' => $a->archived_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'appointment_type_label' => $appointmentTypeLabel,
            'final_status_label' => $this->clinicStatusLabel($a->status),
            'payment_status_label' => $a->paid ? 'Paid' : 'Unpaid',
            'assigned_veterinarian' => null,
            'ownerName' => $ownerName,
            'contactNumber' => $contactNumber,
            'ownerAccountDeleted' => $ownerAccountDeleted,
            'owner_account_deleted' => $ownerAccountDeleted,
            'isWalkin' => $walkin !== null,
            'owner' => [
                'name' => $ownerName,
                'contact_number' => $contactNumber,
                'email' => $ownerEmail,
                'customer_type' => $walkin ? 'Walk-in customer' : 'Registered customer',
                'account_deleted' => $ownerAccountDeleted,
            ],
            'pet' => $pet ? [
                'id' => $pet->pet_id,
                'name' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'gender' => $pet->gender,
                'birthdate' => $pet->birthdate,
                'weight' => $pet->weight,
                'color' => $pet->color,
                'size' => $pet->size,
                'medical_conditions' => $pet->medical_conditions,
                'known_allergies' => null,
                'current_medications' => null,
            ] : null,
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
            'record' => $this->formatRecord($record, $a->id),
            'activity' => [
                'created_by_name' => null,
                'record_created_at' => $record?->created_at?->toIso8601String(),
                'assessment_completed_by_name' => null,
                'assessment_completed_at' => $a->consultation_finished_at?->toIso8601String(),
                'edited_by_name' => null,
                'record_updated_at' => $record?->updated_at?->toIso8601String(),
                'signed_corrections' => null,
            ],
        ];
    }

    private function appointmentRelations(): array
    {
        $relations = [
            'user',
            'walkin',
            'pet',
        ];

        if (Schema::hasTable('unregistered_customers')) {
            $relations[] = 'pet.unregisteredCustomer';
        }

        if (Schema::hasTable('unregistered_customers')
            && Schema::hasColumn('walkins', 'unregistered_customer_id')) {
            $relations[] = 'walkin.unregisteredCustomer';
        }

        if (Schema::hasTable('time_windows')) {
            $relations[] = 'timeWindow';
        }
        if (Schema::hasTable('clinic_vitals')) {
            $relations[] = 'vitals';
        }
        if (Schema::hasTable('clinic_records')) {
            $relations[] = 'record';
            if (Schema::hasTable('clinic_medications')) {
                $relations[] = 'record.medications';
            }
            if (Schema::hasTable('clinic_attachments')) {
                $relations[] = 'record.attachments';
            }
        }

        return $relations;
    }

    private function ownerAccountDeleted(ClinicAppointment $appointment): bool
    {
        $userAttributes = $appointment->user?->getAttributes() ?? [];
        if (($userAttributes['account_deleted_at'] ?? null) !== null) {
            return true;
        }

        $unregisteredAttributes = $appointment->walkin?->unregisteredCustomer?->getAttributes() ?? [];

        return ($unregisteredAttributes['account_deleted_at'] ?? null) !== null;
    }

    private function clinicStatusLabel(?string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'Waiting to Arrive',
            'checked_in' => 'Checked In',
            'in_consultation' => 'In Consultation',
            'for_payment' => 'For Payment',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No Show',
            default => 'Data is currently unavailable',
        };
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
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
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

    private function assertClinicalContentEditable(
        ClinicAppointment $appointment,
    ): void {
        if (in_array(
            $appointment->status,
            self::CLINICAL_CONTENT_EDITABLE_STATUSES,
            true,
        )) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Clinical records can only be modified while the appointment is Checked In, In Consultation, or For Payment.',
        ], 409));
    }
}
