<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\GroomingMedicalConcern;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminGroomingMedicalConcernController extends Controller
{
    private const ACTIVE_GROOMING_BOOKING_STATUSES = [
        'checked_in',
        'in_progress',
        'waiting',
    ];

    private const CUSTOMER_VISIBLE_FIELDS = [
        'category',
        'severity',
        'customer_message',
        'recommended_grooming_action',
        'acknowledgment_required',
        'consent_required',
    ];

    private const RESPONSE_RELATIONS = [
        'booking:booking_id,booking_reference',
        'pet:pet_id,pet_name,species',
        'actionAppliedBy:user_id,first_name,last_name',
        'clinicAppointment:id,appointment_reference',
    ];

    public function index(int $bookingId, int $bookingPetId)
    {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);

        $concerns = GroomingMedicalConcern::query()
            ->where('booking_id', $bookingId)
            ->where('booking_pet_id', $bookingPetId)
            ->where('pet_id', $context->pet_id)
            ->with(self::RESPONSE_RELATIONS)
            ->withExists('responses')
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GroomingMedicalConcern $concern) => $this->formatConcern($concern))
            ->values();

        return response()->json([
            'success' => true,
            'booking_pet' => $this->formatBookingPetContext($context),
            'concerns' => $concerns,
        ]);
    }

    public function store(Request $request, int $bookingId, int $bookingPetId)
    {
        $this->findBookingPetContext($bookingId, $bookingPetId);

        $this->trimRequestStrings($request, [
            'category',
            'severity',
            'internal_description',
            'customer_message',
            'recommended_grooming_action',
            'report_token',
        ]);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'severity' => [
                'required',
                'string',
                Rule::in(GroomingMedicalConcern::SEVERITIES),
            ],
            'internal_description' => ['required', 'string'],
            'customer_message' => ['required', 'string'],
            'recommended_grooming_action' => [
                'required',
                'string',
                Rule::in(GroomingMedicalConcern::GROOMING_ACTIONS),
            ],
            'acknowledgment_required' => ['sometimes', 'boolean'],
            'consent_required' => ['sometimes', 'boolean'],
            'report_token' => ['sometimes', 'nullable', 'uuid'],
            ...$this->serverManagedRules(['report_token']),
        ]);

        $reportToken = $validated['report_token'] ?? null;

        try {
            return DB::transaction(function () use (
                $request,
                $bookingId,
                $bookingPetId,
                $validated,
                $reportToken,
            ) {
                $context = $this->findBookingPetContext(
                    $bookingId,
                    $bookingPetId,
                    lockForUpdate: true,
                );

                if ($reportToken !== null) {
                    $existingTokenConcern = GroomingMedicalConcern::query()
                        ->where('report_token', $reportToken)
                        ->lockForUpdate()
                        ->first();

                    if ($existingTokenConcern) {
                        return $this->idempotencyResponse(
                            $existingTokenConcern,
                            $bookingId,
                            $bookingPetId,
                        );
                    }
                }

                $this->ensureActiveGroomingContext($context);

                $duplicate = $this->findActiveCategoryConcern(
                    $bookingPetId,
                    $validated['category'],
                );

                if ($duplicate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'An active medical concern with this category already exists for the selected booking pet.',
                        'concern' => $this->formatConcern($this->loadResponseContext($duplicate)),
                    ], 409);
                }

                $acknowledgmentRequired = (bool) ($validated['acknowledgment_required'] ?? false);
                $consentRequired = (bool) ($validated['consent_required'] ?? false);

                $concern = GroomingMedicalConcern::create([
                    'report_token' => $reportToken,
                    'booking_id' => $context->booking_id,
                    'booking_pet_id' => $context->booking_pet_id,
                    'pet_id' => $context->pet_id,
                    'reported_by_user_id' => $request->user()->user_id,
                    'reported_by_name' => $this->formatAuthenticatedUserName($request->user()),
                    'reported_at' => now(),
                    'category' => $validated['category'],
                    'severity' => $validated['severity'],
                    'internal_description' => $validated['internal_description'],
                    'customer_message' => $validated['customer_message'],
                    'recommended_grooming_action' => $validated['recommended_grooming_action'],
                    'status' => GroomingMedicalConcern::STATUS_OPEN,
                    'acknowledgment_required' => $acknowledgmentRequired,
                    'consent_required' => $consentRequired,
                    'customer_response_status' => $acknowledgmentRequired || $consentRequired
                        ? GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
                        : GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Grooming medical concern reported.',
                    'idempotent_replay' => false,
                    'concern' => $this->formatConcern($this->loadResponseContext($concern)),
                ], 201);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($reportToken === null) {
                throw $exception;
            }

            $existingTokenConcern = GroomingMedicalConcern::query()
                ->where('report_token', $reportToken)
                ->first();

            if (! $existingTokenConcern) {
                throw $exception;
            }

            return $this->idempotencyResponse(
                $existingTokenConcern,
                $bookingId,
                $bookingPetId,
            );
        }
    }

    public function show(int $bookingId, int $bookingPetId, int $concernId)
    {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $concern = $this->findScopedConcern($context, $concernId);

        return response()->json([
            'success' => true,
            'concern' => $this->formatConcern($this->loadResponseContext($concern)),
        ]);
    }

    public function update(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $this->findScopedConcern($context, $concernId);

        $this->trimRequestStrings($request, [
            'internal_description',
            'internal_resolution_notes',
            'category',
            'severity',
            'customer_message',
            'recommended_grooming_action',
        ]);

        $validated = $request->validate([
            'internal_description' => ['sometimes', 'required', 'string'],
            'internal_resolution_notes' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'required', 'string', 'max:50'],
            'severity' => [
                'sometimes',
                'required',
                'string',
                Rule::in(GroomingMedicalConcern::SEVERITIES),
            ],
            'customer_message' => ['sometimes', 'required', 'string'],
            'recommended_grooming_action' => [
                'sometimes',
                'required',
                'string',
                Rule::in(GroomingMedicalConcern::GROOMING_ACTIONS),
            ],
            'acknowledgment_required' => ['sometimes', 'boolean'],
            'consent_required' => ['sometimes', 'boolean'],
            ...$this->serverManagedRules(['internal_resolution_notes']),
        ]);

        $editableFields = [
            'internal_description',
            'internal_resolution_notes',
            ...self::CUSTOMER_VISIBLE_FIELDS,
        ];
        $changes = array_intersect_key($validated, array_flip($editableFields));

        if ($changes === []) {
            throw ValidationException::withMessages([
                'concern' => ['Provide at least one editable medical-concern field.'],
            ]);
        }

        return DB::transaction(function () use (
            $request,
            $bookingId,
            $bookingPetId,
            $concernId,
            $changes,
        ) {
            $context = $this->findBookingPetContext(
                $bookingId,
                $bookingPetId,
                lockForUpdate: true,
            );
            $concern = $this->findScopedConcern(
                $context,
                $concernId,
                lockForUpdate: true,
            );

            if ($this->isTerminal($concern)) {
                return $this->conflict('Resolved or cancelled medical concerns cannot be updated.');
            }

            $submittedCustomerFields = array_values(array_filter(
                self::CUSTOMER_VISIBLE_FIELDS,
                fn (string $field) => $request->exists($field),
            ));

            if (
                $submittedCustomerFields !== []
                && ! $this->customerVisibleFieldsAreEditable($concern)
            ) {
                return $this->conflict(
                    'Customer-visible concern facts cannot be changed after notification or a customer response. Cancel this concern and create a corrected replacement.',
                );
            }

            if (array_key_exists('category', $changes)) {
                $duplicate = $this->findActiveCategoryConcern(
                    $bookingPetId,
                    $changes['category'],
                    excludingConcernId: $concern->id,
                );

                if ($duplicate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'An active medical concern with this category already exists for the selected booking pet.',
                        'concern' => $this->formatConcern($this->loadResponseContext($duplicate)),
                    ], 409);
                }
            }

            $concern->fill($changes);

            if (
                array_key_exists('acknowledgment_required', $changes)
                || array_key_exists('consent_required', $changes)
            ) {
                $concern->customer_response_status = (
                    $concern->acknowledgment_required
                    || $concern->consent_required
                )
                    ? GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
                    : GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED;
            }

            $concern->save();

            return response()->json([
                'success' => true,
                'message' => 'Grooming medical concern updated.',
                'concern' => $this->formatConcern($this->loadResponseContext($concern)),
            ]);
        });
    }

    public function cancel(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $this->findScopedConcern($context, $concernId);

        $this->trimRequestStrings($request, [
            'internal_cancellation_reason',
            'customer_cancellation_summary',
        ]);

        $validated = $request->validate([
            'internal_cancellation_reason' => ['required', 'string'],
            'customer_cancellation_summary' => ['sometimes', 'nullable', 'string'],
            ...$this->serverManagedRules(),
        ]);

        return DB::transaction(function () use (
            $request,
            $bookingId,
            $bookingPetId,
            $concernId,
            $validated,
        ) {
            $context = $this->findBookingPetContext(
                $bookingId,
                $bookingPetId,
                lockForUpdate: true,
            );
            $concern = $this->findScopedConcern(
                $context,
                $concernId,
                lockForUpdate: true,
            );

            if ($this->isTerminal($concern)) {
                return $this->conflict('This medical concern is already terminal and cannot be cancelled.');
            }

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_CANCELLED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->user_id,
                'resolved_by_name' => $this->formatAuthenticatedUserName($request->user()),
                'internal_resolution_notes' => $validated['internal_cancellation_reason'],
                'customer_resolution_summary' => $validated['customer_cancellation_summary'] ?? null,
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Grooming medical concern cancelled. The historical record was preserved.',
                'concern' => $this->formatConcern($this->loadResponseContext($concern)),
            ]);
        });
    }

    public function resolve(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $this->findScopedConcern($context, $concernId);

        $this->trimRequestStrings($request, [
            'internal_resolution_notes',
            'customer_resolution_summary',
        ]);

        $validated = $request->validate([
            'internal_resolution_notes' => ['required', 'string'],
            'customer_resolution_summary' => ['required', 'string'],
            ...$this->serverManagedRules([
                'internal_resolution_notes',
                'customer_resolution_summary',
            ]),
        ]);

        return DB::transaction(function () use (
            $request,
            $bookingId,
            $bookingPetId,
            $concernId,
            $validated,
        ) {
            $context = $this->findBookingPetContext(
                $bookingId,
                $bookingPetId,
                lockForUpdate: true,
            );
            $concern = $this->findScopedConcern(
                $context,
                $concernId,
                lockForUpdate: true,
            );

            if ($this->isTerminal($concern)) {
                return $this->conflict('This medical concern is already terminal and cannot be resolved again.');
            }

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->user_id,
                'resolved_by_name' => $this->formatAuthenticatedUserName($request->user()),
                'internal_resolution_notes' => $validated['internal_resolution_notes'],
                'customer_resolution_summary' => $validated['customer_resolution_summary'],
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Grooming medical concern resolved.',
                'concern' => $this->formatConcern($this->loadResponseContext($concern)),
            ]);
        });
    }

    private function findBookingPetContext(
        int $bookingId,
        int $bookingPetId,
        bool $lockForUpdate = false,
    ): BookingPet {
        if ($lockForUpdate) {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                $this->throwBookingPetNotFound();
            }

            $bookingPet = BookingPet::query()
                ->whereKey($bookingPetId)
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();

            if (! $bookingPet || $bookingPet->pet_id === null) {
                $this->throwBookingPetNotFound();
            }

            $pet = Pet::query()
                ->whereKey($bookingPet->pet_id)
                ->lockForUpdate()
                ->first();

            if (! $pet) {
                $this->throwBookingPetNotFound();
            }

            $bookingPet->setRelation('booking', $booking);
            $bookingPet->setRelation('pet', $pet);

            return $bookingPet;
        }

        $bookingPet = BookingPet::query()
            ->whereKey($bookingPetId)
            ->where('booking_id', $bookingId)
            ->with([
                'booking:booking_id,booking_reference,status,archived_at',
                'pet:pet_id,pet_name,species,is_archived',
            ])
            ->first();

        if (
            ! $bookingPet
            || $bookingPet->pet_id === null
            || ! $bookingPet->booking
            || ! $bookingPet->pet
        ) {
            $this->throwBookingPetNotFound();
        }

        return $bookingPet;
    }

    private function findScopedConcern(
        BookingPet $context,
        int $concernId,
        bool $lockForUpdate = false,
    ): GroomingMedicalConcern {
        $query = GroomingMedicalConcern::query()
            ->whereKey($concernId)
            ->where('booking_id', $context->booking_id)
            ->where('booking_pet_id', $context->booking_pet_id)
            ->where('pet_id', $context->pet_id)
            ->withExists('responses');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $concern = $query->first();

        if (! $concern) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Medical concern not found.',
            ], 404));
        }

        return $concern;
    }

    private function ensureActiveGroomingContext(BookingPet $context): void
    {
        $booking = $context->booking;
        $pet = $context->pet;
        $eligible = in_array(
            $booking->status,
            self::ACTIVE_GROOMING_BOOKING_STATUSES,
            true,
        )
            && $booking->archived_at === null
            && $context->grooming_end_time === null
            && ! (bool) $pet->is_archived;

        if (! $eligible) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'A medical concern can only be reported while the selected pet is in an active grooming workflow.',
            ], 422));
        }
    }

    private function findActiveCategoryConcern(
        int $bookingPetId,
        string $category,
        ?int $excludingConcernId = null,
    ): ?GroomingMedicalConcern {
        return GroomingMedicalConcern::query()
            ->where('booking_pet_id', $bookingPetId)
            ->active()
            ->whereRaw('LOWER(category) = ?', [mb_strtolower($category)])
            ->when(
                $excludingConcernId !== null,
                fn ($query) => $query->whereKeyNot($excludingConcernId),
            )
            ->lockForUpdate()
            ->first();
    }

    private function idempotencyResponse(
        GroomingMedicalConcern $concern,
        int $bookingId,
        int $bookingPetId,
    ) {
        if (
            (int) $concern->booking_id !== $bookingId
            || (int) $concern->booking_pet_id !== $bookingPetId
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This report token is already associated with a different booking pet.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'This medical concern was already reported.',
            'idempotent_replay' => true,
            'concern' => $this->formatConcern($this->loadResponseContext($concern)),
        ]);
    }

    private function loadResponseContext(
        GroomingMedicalConcern $concern,
    ): GroomingMedicalConcern {
        return $concern->fresh(self::RESPONSE_RELATIONS)->loadExists('responses');
    }

    private function formatBookingPetContext(BookingPet $context): array
    {
        return [
            'booking_id' => $context->booking_id,
            'booking_reference' => $context->booking?->booking_reference,
            'booking_pet_id' => $context->booking_pet_id,
            'pet_id' => $context->pet_id,
            'pet_name' => $context->pet?->pet_name,
            'pet_species' => $context->pet?->species,
        ];
    }

    private function formatConcern(GroomingMedicalConcern $concern): array
    {
        $terminal = $this->isTerminal($concern);
        $customerVisibleFieldsEditable = $this->customerVisibleFieldsAreEditable($concern);

        return [
            'id' => $concern->id,
            'public_id' => $concern->public_id,
            'booking_id' => $concern->booking_id,
            'booking_reference' => $concern->booking?->booking_reference,
            'booking_pet_id' => $concern->booking_pet_id,
            'pet_id' => $concern->pet_id,
            'pet_name' => $concern->pet?->pet_name,
            'pet_species' => $concern->pet?->species,
            'reported_by_name' => $concern->reported_by_name,
            'reported_at' => $concern->reported_at?->toIso8601String(),
            'category' => $concern->category,
            'severity' => $concern->severity,
            'internal_description' => $concern->internal_description,
            'customer_message' => $concern->customer_message,
            'recommended_grooming_action' => $concern->recommended_grooming_action,
            'recommended_action_is_advisory' => true,
            'applied_grooming_action' => $concern->applied_grooming_action,
            'action_applied_at' => $concern->action_applied_at?->toIso8601String(),
            'action_applied_by_user_id' => $concern->action_applied_by_user_id,
            'action_applied_by_name' => $this->formatUserName($concern->actionAppliedBy),
            'status' => $concern->status,
            'acknowledgment_required' => $concern->acknowledgment_required,
            'consent_required' => $concern->consent_required,
            'customer_response_status' => $concern->customer_response_status,
            'customer_notified_at' => $concern->customer_notified_at?->toIso8601String(),
            'has_customer_response' => (bool) ($concern->responses_exists ?? false),
            'clinic_appointment_id' => $concern->clinic_appointment_id,
            'clinic_appointment_reference' => $concern->clinicAppointment?->appointment_reference,
            'customer_resolution_summary' => $concern->customer_resolution_summary,
            'internal_resolution_notes' => $concern->internal_resolution_notes,
            'resolved_by_name' => $concern->resolved_by_name,
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
            'created_at' => $concern->created_at?->toIso8601String(),
            'updated_at' => $concern->updated_at?->toIso8601String(),
            'customer_visible_fields_editable' => $customerVisibleFieldsEditable,
            'staff_internal_fields_editable' => ! $terminal,
            'available_staff_actions' => $terminal
                ? []
                : ['update', 'cancel', 'resolve'],
        ];
    }

    private function customerVisibleFieldsAreEditable(
        GroomingMedicalConcern $concern,
    ): bool {
        return ! $this->isTerminal($concern)
            && $concern->customer_notified_at === null
            && ! (bool) ($concern->responses_exists ?? $concern->responses()->exists());
    }

    private function isTerminal(GroomingMedicalConcern $concern): bool
    {
        return in_array($concern->status, [
            GroomingMedicalConcern::STATUS_RESOLVED,
            GroomingMedicalConcern::STATUS_CANCELLED,
        ], true);
    }

    private function serverManagedRules(array $allowedFields = []): array
    {
        $managedFields = [
            'id',
            'public_id',
            'report_token',
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'reported_by_user_id',
            'reported_by_name',
            'reported_at',
            'status',
            'customer_response_status',
            'customer_notified_at',
            'clinic_appointment_id',
            'applied_grooming_action',
            'action_applied_at',
            'action_applied_by_user_id',
            'customer_resolution_summary',
            'internal_resolution_notes',
            'resolved_at',
            'resolved_by_user_id',
            'resolved_by_name',
            'created_at',
            'updated_at',
        ];

        return collect($managedFields)
            ->reject(fn (string $field) => in_array($field, $allowedFields, true))
            ->mapWithKeys(fn (string $field) => [$field => ['prohibited']])
            ->all();
    }

    private function trimRequestStrings(Request $request, array $fields): void
    {
        $trimmed = [];

        foreach ($fields as $field) {
            if ($request->exists($field) && is_string($request->input($field))) {
                $trimmed[$field] = trim($request->input($field));
            }
        }

        if ($trimmed !== []) {
            $request->merge($trimmed);
        }
    }

    private function formatAuthenticatedUserName(User $user): string
    {
        return $this->formatUserName($user) ?? "Staff user #{$user->user_id}";
    }

    private function formatUserName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function throwBookingPetNotFound(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Grooming booking pet not found.',
        ], 404));
    }

    private function conflict(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }
}
