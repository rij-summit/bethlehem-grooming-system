<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    private const SAFETY_OVERRIDE_MAX_LENGTH = 1000;

    private const RESPONSE_RELATIONS = [
        'booking:booking_id,booking_reference,status,archived_at',
        'bookingPet:booking_pet_id,booking_id,pet_id,grooming_state,grooming_start_time,grooming_end_time',
        'pet:pet_id,user_id,pet_name,species',
        'pet.user:user_id,role',
        'actionAppliedBy:user_id,first_name,last_name',
        'clinicAppointment:id,appointment_reference',
        'responses:id,concern_id,responded_by_name,response_kind,decision,responded_at',
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

            if (
                $request->exists('internal_resolution_notes')
                && $concern->applied_grooming_action !== null
            ) {
                return $this->conflict(
                    'Internal action-audit notes cannot be overwritten after a grooming action has been applied.',
                );
            }

            if (
                $request->exists('recommended_grooming_action')
                && $concern->applied_grooming_action !== null
            ) {
                return $this->conflict(
                    'The recommended grooming action cannot be changed after it has been operationally applied.',
                );
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

            if ($concern->applied_grooming_action !== null) {
                return $this->conflict(
                    'A medical concern cannot be cancelled after an operational grooming action has been applied.',
                );
            }

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_CANCELLED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->user_id,
                'resolved_by_name' => $this->formatAuthenticatedUserName($request->user()),
                'internal_resolution_notes' => $this->appendInternalAuditEntry(
                    $concern->internal_resolution_notes,
                    $validated['internal_cancellation_reason'],
                ),
                'customer_resolution_summary' => $validated['customer_cancellation_summary'] ?? null,
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Grooming medical concern cancelled. The historical record was preserved.',
                'concern' => $this->formatConcern($this->loadResponseContext($concern)),
            ]);
        });
    }

    public function notifyCustomer(
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $this->findBookingPetContext($bookingId, $bookingPetId);

        return DB::transaction(function () use (
            $bookingId,
            $bookingPetId,
            $concernId,
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

            $existingNotification = CustomerNotification::query()
                ->where('grooming_medical_concern_id', $concern->id)
                ->lockForUpdate()
                ->first();

            if ($existingNotification || $concern->customer_notified_at !== null) {
                if (! $existingNotification || $concern->customer_notified_at === null) {
                    return $this->conflict(
                        'This medical concern has inconsistent notification data and cannot be sent again.',
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'This medical concern was already sent to the customer.',
                    'already_notified' => true,
                    'notification' => $this->formatNotificationResult(
                        $existingNotification,
                        $concern,
                        $context->pet,
                    ),
                    'concern' => $this->formatConcern(
                        $this->loadResponseContext($concern),
                    ),
                ]);
            }

            if ($this->isTerminal($concern)) {
                return $this->conflict(
                    'Resolved or cancelled medical concerns cannot be newly sent to a customer.',
                );
            }

            if (trim((string) $concern->customer_message) === '') {
                throw ValidationException::withMessages([
                    'customer_message' => [
                        'A customer-visible message is required before sending.',
                    ],
                ]);
            }

            if (! $this->hasValidNotificationRequirements($concern)) {
                return $this->conflict(
                    'The concern requirement flags and customer-response status are inconsistent.',
                );
            }

            $customer = User::query()
                ->whereKey($context->pet->user_id)
                ->registeredCustomer()
                ->lockForUpdate()
                ->first();

            if (! $customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'No linked customer account.',
                ], 422);
            }

            $notifiedAt = now();
            $notification = CustomerNotification::create([
                'user_id' => $customer->user_id,
                'booking_id' => $concern->booking_id,
                'grooming_medical_concern_id' => $concern->id,
                'type' => CustomerNotification::TYPE_GROOMING_MEDICAL_CONCERN,
                'message' => $this->customerNotificationMessage(
                    $context->pet,
                    $concern,
                ),
                'is_read' => false,
                'created_at' => $notifiedAt,
            ]);

            $requiresResponse = $concern->acknowledgment_required
                || $concern->consent_required;

            $concern->forceFill([
                'customer_notified_at' => $notifiedAt,
                'status' => $requiresResponse
                    ? GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER
                    : GroomingMedicalConcern::STATUS_OPEN,
                'customer_response_status' => $requiresResponse
                    ? GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
                    : GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Medical concern sent to the customer.',
                'already_notified' => false,
                'notification' => $this->formatNotificationResult(
                    $notification,
                    $concern,
                    $context->pet,
                ),
                'concern' => $this->formatConcern(
                    $this->loadResponseContext($concern),
                ),
            ]);
        });
    }

    public function applyRecommendedAction(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $this->findScopedConcern($context, $concernId);

        $this->trimRequestStrings($request, ['safety_override_reason']);
        $validated = $request->validate([
            'clinic_transfer_stop' => ['sometimes', 'boolean'],
            'safety_override_reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.self::SAFETY_OVERRIDE_MAX_LENGTH,
            ],
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
            $this->setConcernContext($concern, $context);

            if ((bool) ($validated['clinic_transfer_stop'] ?? false)) {
                $availability = $this->clinicTransferStopAvailability($concern);
                if (! $availability['can_stop']) {
                    return $this->conflict($availability['blocked_reason']);
                }
                if (filled($validated['safety_override_reason'] ?? null)) {
                    throw ValidationException::withMessages([
                        'safety_override_reason' => [
                            'A safety override is not used when stopping grooming for an accepted clinic transfer.',
                        ],
                    ]);
                }

                $appliedAt = now();
                $staffName = $this->formatAuthenticatedUserName($request->user());
                $previousAppliedAt = $concern->action_applied_at?->toIso8601String()
                    ?? 'time unavailable';
                $previousStaff = $this->formatUserName($concern->actionAppliedBy)
                    ?? 'staff member unavailable';

                $context->forceFill([
                    'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
                ])->save();
                $concern->forceFill([
                    'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                    'action_applied_at' => $appliedAt,
                    'action_applied_by_user_id' => $request->user()->user_id,
                    'internal_resolution_notes' => $this->appendInternalAuditEntry(
                        $concern->internal_resolution_notes,
                        sprintf(
                            '[Clinic transfer Stop | %s | %s] Previous Pause was applied at %s by %s. Grooming was explicitly stopped before clinic consultation.',
                            $appliedAt->toIso8601String(),
                            $staffName,
                            $previousAppliedAt,
                            $previousStaff,
                        ),
                    ),
                ])->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Grooming stopped for clinic transfer. The pet cannot resume grooming during this visit.',
                    'safety_override_used' => false,
                    'clinic_transfer_stop_applied' => true,
                    'concern' => $this->formatConcern(
                        $this->loadResponseContext($concern),
                    ),
                ]);
            }

            $availability = $this->recommendedActionAvailability($concern);
            if (
                ! $availability['can_apply']
                && ! $availability['safety_override_available']
            ) {
                return $this->conflict($availability['blocked_reason']);
            }

            $overrideReason = trim(
                (string) ($validated['safety_override_reason'] ?? ''),
            );
            $usingSafetyOverride = ! $availability['can_apply']
                && $availability['safety_override_available'];

            if ($usingSafetyOverride && $overrideReason === '') {
                throw ValidationException::withMessages([
                    'safety_override_reason' => [
                        'A safety override reason is required to pause or stop grooming before the required customer response.',
                    ],
                ]);
            }

            if (! $usingSafetyOverride && $overrideReason !== '') {
                throw ValidationException::withMessages([
                    'safety_override_reason' => [
                        'A safety override is not required for this action.',
                    ],
                ]);
            }

            $action = $concern->recommended_grooming_action;
            $stateUpdates = match ($action) {
                GroomingMedicalConcern::ACTION_PAUSE_GROOMING => [
                    'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
                ],
                GroomingMedicalConcern::ACTION_STOP_GROOMING => [
                    'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
                ],
                default => [],
            };

            if ($stateUpdates !== []) {
                $context->forceFill($stateUpdates)->save();
            }

            $appliedAt = now();
            $staffName = $this->formatAuthenticatedUserName($request->user());
            $concernUpdates = [
                'applied_grooming_action' => $action,
                'action_applied_at' => $appliedAt,
                'action_applied_by_user_id' => $request->user()->user_id,
            ];

            if ($usingSafetyOverride) {
                $concernUpdates['internal_resolution_notes'] =
                    $this->appendInternalAuditEntry(
                        $concern->internal_resolution_notes,
                        sprintf(
                            '[Safety override | %s | %s | %s] %s',
                            $appliedAt->toIso8601String(),
                            $staffName,
                            $this->groomingActionLabel($action),
                            $overrideReason,
                        ),
                    );
            }

            $concern->forceFill($concernUpdates)->save();

            return response()->json([
                'success' => true,
                'message' => $usingSafetyOverride
                    ? 'Recommended grooming action applied with a documented safety override.'
                    : 'Recommended grooming action applied.',
                'safety_override_used' => $usingSafetyOverride,
                'concern' => $this->formatConcern(
                    $this->loadResponseContext($concern),
                ),
            ]);
        });
    }

    public function resumeGrooming(
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
            $this->setConcernContext($concern, $context);

            $resumeAvailability = $this->resumeAvailability($concern);
            if (! $resumeAvailability['can_resume']) {
                return $this->conflict($resumeAvailability['blocked_reason']);
            }

            $resolvedAt = now();
            $staffName = $this->formatAuthenticatedUserName($request->user());

            $context->forceFill([
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ])->save();

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_RESOLVED,
                'resolved_at' => $resolvedAt,
                'resolved_by_user_id' => $request->user()->user_id,
                'resolved_by_name' => $staffName,
                'internal_resolution_notes' => $this->appendInternalAuditEntry(
                    $concern->internal_resolution_notes,
                    sprintf(
                        '[Resume grooming | %s | %s] %s',
                        $resolvedAt->toIso8601String(),
                        $staffName,
                        $validated['internal_resolution_notes'],
                    ),
                ),
                'customer_resolution_summary' => $validated['customer_resolution_summary'],
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Grooming resumed and the medical concern was resolved.',
                'concern' => $this->formatConcern(
                    $this->loadResponseContext($concern),
                ),
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

            if (
                $concern->applied_grooming_action
                    === GroomingMedicalConcern::ACTION_PAUSE_GROOMING
                && $context->grooming_state
                    === BookingPet::GROOMING_STATE_PAUSED
            ) {
                return $this->conflict(
                    'A concern that is actively pausing grooming must be closed through Resume Grooming.',
                );
            }

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->user_id,
                'resolved_by_name' => $this->formatAuthenticatedUserName($request->user()),
                'internal_resolution_notes' => $this->appendInternalAuditEntry(
                    $concern->internal_resolution_notes,
                    $validated['internal_resolution_notes'],
                ),
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
        $groomingState = $this->bookingPetGroomingState($context);

        return [
            'booking_id' => $context->booking_id,
            'booking_reference' => $context->booking?->booking_reference,
            'booking_pet_id' => $context->booking_pet_id,
            'pet_id' => $context->pet_id,
            'pet_name' => $context->pet?->pet_name,
            'pet_species' => $context->pet?->species,
            'grooming_state' => $groomingState,
            'grooming_state_label' => $this->groomingStateLabel($groomingState),
        ];
    }

    private function formatConcern(GroomingMedicalConcern $concern): array
    {
        $terminal = $this->isTerminal($concern);
        $customerResponse = $this->safeCustomerResponseSummary($concern);
        $customerVisibleFieldsEditable = $this->customerVisibleFieldsAreEditable($concern);
        $customerAccountLinked = $this->customerAccountLinked($concern);
        $actionAvailability = $this->recommendedActionAvailability($concern);
        $resumeAvailability = $this->resumeAvailability($concern);
        $groomingState = $this->bookingPetGroomingState($concern->bookingPet);
        $availableStaffActions = $terminal
            ? []
            : ['update'];

        if (! $terminal && $concern->applied_grooming_action === null) {
            $availableStaffActions[] = 'cancel';
        }

        if (
            ! $terminal
            && ! (
                $concern->applied_grooming_action
                    === GroomingMedicalConcern::ACTION_PAUSE_GROOMING
                && $groomingState === BookingPet::GROOMING_STATE_PAUSED
            )
        ) {
            $availableStaffActions[] = 'resolve';
        }

        if (
            ! $terminal
            && $concern->customer_notified_at === null
            && trim((string) $concern->customer_message) !== ''
            && $customerAccountLinked
            && $this->hasValidNotificationRequirements($concern)
        ) {
            $availableStaffActions[] = 'notify_customer';
        }

        if (
            $actionAvailability['can_apply']
            || $actionAvailability['safety_override_available']
        ) {
            $availableStaffActions[] = 'apply_recommended_action';
        }

        if ($resumeAvailability['can_resume']) {
            $availableStaffActions[] = 'resume_grooming';
        }
        $clinicTransferStopAvailability = $this->clinicTransferStopAvailability($concern);
        if ($clinicTransferStopAvailability['can_stop']) {
            $availableStaffActions[] = 'stop_for_clinic_transfer';
        }

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
            'booking_pet_grooming_state' => $groomingState,
            'booking_pet_grooming_state_label' => $this->groomingStateLabel($groomingState),
            'recommended_action_can_be_applied' => $actionAvailability['can_apply'],
            'recommended_action_blocked_reason' => $actionAvailability['blocked_reason'],
            'safety_override_available' => $actionAvailability['safety_override_available'],
            'safety_override_required' => $actionAvailability['safety_override_available']
                && ! $actionAvailability['can_apply'],
            'resume_grooming_available' => $resumeAvailability['can_resume'],
            'resume_grooming_blocked_reason' => $resumeAvailability['blocked_reason'],
            'clinic_transfer_stop_available' => $clinicTransferStopAvailability['can_stop'],
            'clinic_transfer_stop_blocked_reason' => $clinicTransferStopAvailability['blocked_reason'],
            'customer_response_requirement' => $this->customerResponseRequirement($concern),
            'status' => $concern->status,
            'acknowledgment_required' => $concern->acknowledgment_required,
            'consent_required' => $concern->consent_required,
            'customer_response_status' => $concern->customer_response_status,
            'customer_notified_at' => $concern->customer_notified_at?->toIso8601String(),
            'has_customer_response' => (bool) ($concern->responses_exists ?? false),
            'customer_response' => $customerResponse,
            'clinic_appointment_id' => $concern->clinic_appointment_id,
            'clinic_appointment_reference' => $concern->clinicAppointment?->appointment_reference,
            'customer_resolution_summary' => $concern->customer_resolution_summary,
            'internal_resolution_notes' => $concern->internal_resolution_notes,
            'resolved_by_name' => $concern->resolved_by_name,
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
            'created_at' => $concern->created_at?->toIso8601String(),
            'updated_at' => $concern->updated_at?->toIso8601String(),
            'customer_visible_fields_editable' => $customerVisibleFieldsEditable,
            'recommended_action_editable' => $customerVisibleFieldsEditable
                && $concern->applied_grooming_action === null,
            'customer_account_linked' => $customerAccountLinked,
            'staff_internal_fields_editable' => ! $terminal,
            'internal_resolution_notes_editable' => ! $terminal
                && $concern->applied_grooming_action === null,
            'available_staff_actions' => $availableStaffActions,
        ];
    }

    private function safeCustomerResponseSummary(
        GroomingMedicalConcern $concern,
    ): ?array {
        $responses = $concern->relationLoaded('responses')
            ? $concern->responses
            : $concern->responses()
                ->select([
                    'id',
                    'concern_id',
                    'responded_by_name',
                    'response_kind',
                    'decision',
                    'responded_at',
                ])
                ->get();
        $response = $responses->firstWhere(
            'response_kind',
            GroomingMedicalConcernResponse::KIND_CONSENT,
        ) ?? $responses->firstWhere(
            'response_kind',
            GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
        );

        if (! $response) {
            return null;
        }

        return [
            'response_kind' => $response->response_kind,
            'decision' => $response->decision,
            'responded_by_name' => $response->responded_by_name,
            'responded_at' => $response->responded_at?->toIso8601String(),
        ];
    }

    private function customerAccountLinked(
        GroomingMedicalConcern $concern,
    ): bool {
        return $concern->pet?->user?->role === 'customer';
    }

    private function hasValidNotificationRequirements(
        GroomingMedicalConcern $concern,
    ): bool {
        $validFlagValues = [0, 1, '0', '1', false, true];

        if (
            ! in_array(
                $concern->getRawOriginal('acknowledgment_required'),
                $validFlagValues,
                true,
            )
            || ! in_array(
                $concern->getRawOriginal('consent_required'),
                $validFlagValues,
                true,
            )
            || ! GroomingMedicalConcern::isValidCustomerResponseStatus(
                (string) $concern->customer_response_status,
            )
        ) {
            return false;
        }

        $requiresResponse = $concern->acknowledgment_required
            || $concern->consent_required;
        $expectedStatus = $requiresResponse
            ? GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
            : GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED;

        return $concern->customer_response_status === $expectedStatus;
    }

    private function recommendedActionAvailability(
        GroomingMedicalConcern $concern,
    ): array {
        $blocked = fn (string $reason, bool $override = false) => [
            'can_apply' => false,
            'blocked_reason' => $reason,
            'safety_override_available' => $override,
        ];

        if ($this->isTerminal($concern)) {
            return $blocked('Resolved or cancelled concerns cannot apply grooming actions.');
        }

        if ($concern->applied_grooming_action !== null) {
            return $blocked('The recommended grooming action has already been applied.');
        }

        $booking = $concern->booking;
        $bookingPet = $concern->bookingPet;
        if (! $booking || ! $bookingPet) {
            return $blocked('The booking-pet grooming context is unavailable.');
        }

        if (! $this->bookingIsOperationallyActive($booking)) {
            return $blocked('The booking is no longer active for grooming actions.');
        }

        $state = $this->bookingPetGroomingState($bookingPet);
        $action = $concern->recommended_grooming_action;
        $stateIsCompatible = match ($action) {
            GroomingMedicalConcern::ACTION_CONTINUE_WITH_OBSERVATION => in_array($state, [
                BookingPet::GROOMING_STATE_NOT_STARTED,
                BookingPet::GROOMING_STATE_IN_PROGRESS,
            ], true),
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING => $state === BookingPet::GROOMING_STATE_IN_PROGRESS,
            GroomingMedicalConcern::ACTION_STOP_GROOMING => in_array($state, [
                BookingPet::GROOMING_STATE_NOT_STARTED,
                BookingPet::GROOMING_STATE_IN_PROGRESS,
                BookingPet::GROOMING_STATE_PAUSED,
            ], true),
            default => false,
        };

        if (! $stateIsCompatible || ! $this->groomingTimestampsMatchState($bookingPet, $state)) {
            return $blocked(sprintf(
                '%s cannot be applied while the selected pet is %s.',
                $this->groomingActionLabel((string) $action),
                strtolower($this->groomingStateLabel($state)),
            ));
        }

        if ($this->requiredCustomerResponseIsComplete($concern)) {
            return [
                'can_apply' => true,
                'blocked_reason' => null,
                'safety_override_available' => false,
            ];
        }

        $overrideAvailable = in_array($action, [
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            GroomingMedicalConcern::ACTION_STOP_GROOMING,
        ], true)
            && $this->customerResponseRequirement($concern) !== 'none'
            && in_array($concern->customer_response_status, [
                GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                GroomingMedicalConcern::CUSTOMER_RESPONSE_DECLINED,
            ], true);

        $requirement = $this->customerResponseRequirement($concern) === 'consent'
            ? 'customer approval'
            : 'customer acknowledgment';

        return $blocked(
            ucfirst($requirement).' is required before this action can be applied normally.',
            $overrideAvailable,
        );
    }

    private function resumeAvailability(
        GroomingMedicalConcern $concern,
    ): array {
        $blocked = fn (string $reason) => [
            'can_resume' => false,
            'blocked_reason' => $reason,
        ];

        if (
            Schema::hasTable('grooming_clinic_referrals')
            && $concern->clinicReferral()
                ->whereIn('status', [
                    GroomingClinicReferral::STATUS_ACCEPTED,
                    GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                    GroomingClinicReferral::STATUS_COMPLETED,
                ])
                ->exists()
        ) {
            return $blocked(
                'Grooming cannot resume because this pet was transferred to clinic care and the grooming session was ended.',
            );
        }

        if ($this->isTerminal($concern)) {
            return $blocked('Resolved or cancelled concerns cannot resume grooming.');
        }

        if (
            $concern->applied_grooming_action
            !== GroomingMedicalConcern::ACTION_PAUSE_GROOMING
        ) {
            return $blocked('Resume Grooming is available only after an applied Pause Grooming action.');
        }

        if (! $concern->booking || ! $this->bookingIsOperationallyActive($concern->booking)) {
            return $blocked('The booking is no longer active for grooming.');
        }

        $bookingPet = $concern->bookingPet;
        $state = $this->bookingPetGroomingState($bookingPet);
        if (
            ! $bookingPet
            || $state !== BookingPet::GROOMING_STATE_PAUSED
            || ! $this->groomingTimestampsMatchState($bookingPet, $state)
        ) {
            return $blocked('Only the paused pet associated with this concern can resume grooming.');
        }

        if (! $this->requiredCustomerResponseIsComplete($concern)) {
            return $blocked(
                $this->customerResponseRequirement($concern) === 'consent'
                    ? 'Customer approval is required before grooming can resume.'
                    : 'Customer acknowledgment is required before grooming can resume.',
            );
        }

        return [
            'can_resume' => true,
            'blocked_reason' => null,
        ];
    }

    /** @return array{can_stop: bool, blocked_reason: ?string} */
    private function clinicTransferStopAvailability(
        GroomingMedicalConcern $concern,
    ): array {
        $blocked = fn (string $reason) => [
            'can_stop' => false,
            'blocked_reason' => $reason,
        ];

        if (! Schema::hasTable('grooming_clinic_referrals')) {
            return $blocked('No accepted clinic referral is linked to this concern.');
        }

        $referral = $concern->clinicReferral()->first();
        if (! $referral || $referral->status !== GroomingClinicReferral::STATUS_ACCEPTED) {
            return $blocked('Only an accepted clinic referral can permanently stop a paused grooming session.');
        }

        $bookingPet = $concern->bookingPet;
        if (
            ! $bookingPet
            || $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_PAUSED
            || $bookingPet->grooming_end_time !== null
            || $concern->applied_grooming_action !== GroomingMedicalConcern::ACTION_PAUSE_GROOMING
            || $concern->action_applied_at === null
            || $concern->action_applied_by_user_id === null
        ) {
            return $blocked('The exact accepted referral must still have an applied Pause Grooming action on its paused pet.');
        }

        return ['can_stop' => true, 'blocked_reason' => null];
    }

    private function requiredCustomerResponseIsComplete(
        GroomingMedicalConcern $concern,
    ): bool {
        return match ($this->customerResponseRequirement($concern)) {
            'consent' => $concern->customer_response_status
                === GroomingMedicalConcern::CUSTOMER_RESPONSE_APPROVED,
            'acknowledgment' => $concern->customer_response_status
                === GroomingMedicalConcern::CUSTOMER_RESPONSE_ACKNOWLEDGED,
            default => true,
        };
    }

    private function customerResponseRequirement(
        GroomingMedicalConcern $concern,
    ): string {
        if ($concern->consent_required) {
            return 'consent';
        }

        if ($concern->acknowledgment_required) {
            return 'acknowledgment';
        }

        return 'none';
    }

    private function bookingIsOperationallyActive(Booking $booking): bool
    {
        return $booking->archived_at === null
            && in_array(
                $booking->status,
                self::ACTIVE_GROOMING_BOOKING_STATUSES,
                true,
            );
    }

    private function groomingTimestampsMatchState(
        BookingPet $bookingPet,
        string $state,
    ): bool {
        return match ($state) {
            BookingPet::GROOMING_STATE_NOT_STARTED => $bookingPet->grooming_start_time === null
                && $bookingPet->grooming_end_time === null,
            BookingPet::GROOMING_STATE_IN_PROGRESS,
            BookingPet::GROOMING_STATE_PAUSED => $bookingPet->grooming_start_time !== null
                && $bookingPet->grooming_end_time === null,
            BookingPet::GROOMING_STATE_STOPPED => $bookingPet->grooming_end_time === null,
            BookingPet::GROOMING_STATE_FINISHED => $bookingPet->grooming_start_time !== null
                && $bookingPet->grooming_end_time !== null,
            default => false,
        };
    }

    private function bookingPetGroomingState(?BookingPet $bookingPet): string
    {
        if (! $bookingPet) {
            return BookingPet::GROOMING_STATE_NOT_STARTED;
        }

        $state = (string) $bookingPet->grooming_state;

        return BookingPet::isValidGroomingState($state)
            ? $state
            : BookingPet::GROOMING_STATE_NOT_STARTED;
    }

    private function groomingStateLabel(string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_IN_PROGRESS => 'In progress',
            BookingPet::GROOMING_STATE_PAUSED => 'Paused',
            BookingPet::GROOMING_STATE_STOPPED => 'Stopped',
            BookingPet::GROOMING_STATE_FINISHED => 'Finished',
            default => 'Not started',
        };
    }

    private function groomingActionLabel(string $action): string
    {
        return match ($action) {
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING => 'Pause grooming',
            GroomingMedicalConcern::ACTION_STOP_GROOMING => 'Stop grooming',
            default => 'Continue with observation',
        };
    }

    private function appendInternalAuditEntry(
        ?string $existingNotes,
        string $entry,
    ): string {
        $existing = trim((string) $existingNotes);
        $entry = trim($entry);

        return $existing === '' ? $entry : "{$existing}\n\n{$entry}";
    }

    private function setConcernContext(
        GroomingMedicalConcern $concern,
        BookingPet $context,
    ): void {
        $concern->setRelation('booking', $context->booking);
        $concern->setRelation('bookingPet', $context);
        $concern->setRelation('pet', $context->pet);
    }

    private function customerNotificationMessage(
        Pet $pet,
        GroomingMedicalConcern $concern,
    ): string {
        return sprintf(
            'Medical concern for %s: %s',
            $pet->pet_name,
            Str::limit(trim((string) $concern->customer_message), 180),
        );
    }

    private function formatNotificationResult(
        CustomerNotification $notification,
        GroomingMedicalConcern $concern,
        Pet $pet,
    ): array {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'message' => $notification->message,
            'is_read' => (bool) $notification->is_read,
            'created_at' => $notification->created_at?->toIso8601String(),
            'concern_public_id' => $concern->public_id,
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
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
