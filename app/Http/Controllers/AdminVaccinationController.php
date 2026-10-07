<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use App\Services\InventoryBatchBalanceService;
use App\Services\InventoryStockMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminVaccinationController extends Controller
{
    private const RESPONSE_RELATIONS = [
        'clinicAppointment:id,appointment_reference',
        'inventoryItem:item_id,item_name,category',
        'administeredBy:user_id,first_name,last_name',
        'recordedBy:user_id,first_name,last_name',
        'publishedBy:user_id,first_name,last_name',
        'voidedBy:user_id,first_name,last_name',
    ];

    public function __construct(
        private readonly InventoryBatchBalanceService $batchBalances,
        private readonly InventoryStockMovementService $stockMovements,
    ) {
    }

    public function options(Request $request)
    {
        $actor = $request->user();
        $items = InventoryItem::query()
            ->where('category', 'vaccine')
            ->where('is_active', 1)
            ->orderBy('item_name')
            ->get(['item_id', 'item_name', 'unit', 'quantity_on_hand']);
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));

        $vaccines = $items->map(function (InventoryItem $item) use ($balances) {
            $balance = $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance();
            $batch = $this->batchBalances->nextAvailableBatch($balance);

            return [
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'deduction_supported' => $this->supportsSingleDoseDeduction($item),
                'available_quantity' => min(
                    (float) $item->quantity_on_hand,
                    (float) $balance['unexpired_quantity'],
                ),
                'next_batch' => $batch ? [
                    'batch_number' => $batch['batch_number'],
                    'expiry_date' => $batch['expiry_date'],
                ] : null,
            ];
        })->values();

        $veterinarians = $this->eligibleProviders($actor)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['user_id', 'first_name', 'last_name'])
            ->sortByDesc(fn (User $user) => (int) $user->user_id === (int) $actor->user_id)
            ->map(fn (User $user) => [
                'user_id' => $user->user_id,
                'name' => $this->formatUserName($user) ?? "User #{$user->user_id}",
                'is_current' => (int) $user->user_id === (int) $actor->user_id,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'vaccines' => $vaccines,
            'veterinarians' => $veterinarians,
            'current_user_id' => $actor->user_id,
            'can_manage_staff' => $actor->role === 'admin',
        ]);
    }

    public function index(int $petId)
    {
        $pet = $this->findPet($petId);

        $records = VaccinationRecord::query()
            ->where('pet_id', $pet->pet_id)
            ->with(self::RESPONSE_RELATIONS)
            ->orderByDesc('administered_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VaccinationRecord $record) => $this->formatRecord($record))
            ->values();

        return response()->json([
            'success' => true,
            'pet' => $this->formatPet($pet),
            'vaccinations' => $records,
        ]);
    }

    public function store(Request $request, int $petId)
    {
        $pet = $this->findPet($petId);
        $data = $this->validateClinicalData($request, $pet->pet_id);
        $data = $this->addInventorySnapshot($data);
        $data = $this->addProviderSnapshot($data);

        if (! empty($data['clinic_appointment_id'])) {
            $caseStatus = ClinicAppointment::query()->whereKey($data['clinic_appointment_id'])->value('status');
            if (! in_array($caseStatus, ClinicAppointment::CLINICAL_CONTENT_EDITABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'clinic_appointment_id' => 'This case is no longer ongoing. Start a new case to add a vaccination.',
                ]);
            }
        }

        $record = VaccinationRecord::create([
            ...$data,
            'pet_id' => $pet->pet_id,
            'recorded_by_user_id' => $request->user()->user_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vaccination draft created.',
            'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
        ], 201);
    }

    public function show(int $petId, int $vaccinationId)
    {
        $this->findPet($petId);
        $record = $this->findScopedRecord($petId, $vaccinationId);

        return response()->json([
            'success' => true,
            'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
        ]);
    }

    public function update(Request $request, int $petId, int $vaccinationId)
    {
        $this->findPet($petId);

        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            if ($record->published_at !== null || $record->voided_at !== null) {
                return $this->conflict(
                    'Published or voided vaccination records cannot be edited. Void an incorrect published record and create a corrected replacement.',
                );
            }

            $data = $this->validateClinicalData($request, $petId, $record);
            $data = $this->addInventorySnapshot($data);
            $data = $this->addProviderSnapshot($data);

            $record->fill($data);
            $record->save();

            return response()->json([
                'success' => true,
                'message' => 'Vaccination draft updated.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    public function publish(Request $request, int $petId, int $vaccinationId)
    {
        $pet = $this->findPet($petId);
        $validated = $request->validate([
            ...$this->serverManagedRules(),
            'finish_case' => ['sometimes', 'boolean'],
            'consume_inventory' => ['sometimes', 'boolean'],
        ]);
        $finishCase = (bool) ($validated['finish_case'] ?? false);

        return DB::transaction(function () use ($request, $pet, $petId, $vaccinationId, $finishCase, $validated) {
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            if ($record->voided_at !== null) {
                return $this->conflict('A voided vaccination record cannot be published again.');
            }

            if ($record->published_at !== null) {
                return $this->conflict('This vaccination record has already been published.');
            }

            if (! $record->hasPublishingRequirements()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The vaccination draft is not ready to publish.',
                    'errors' => [
                        'publication' => [
                            'Vaccine, administration date, and an administering provider are required.',
                        ],
                    ],
                ], 422);
            }

            $usesInventory = $record->inventory_item_id !== null;
            if (array_key_exists('consume_inventory', $validated) && (bool) $validated['consume_inventory'] !== $usesInventory) {
                throw ValidationException::withMessages(['inventory_item_id' => 'The Inventory link has changed. Reload and review the vaccination draft before publishing.']);
            }
            if (! $usesInventory && $finishCase) {
                throw ValidationException::withMessages([
                    'finish_case' => 'Historical or external records cannot finish a current Clinic case.',
                ]);
            }

            if ($usesInventory && $record->administered_date->toDateString() !== now()->toDateString()) {
                throw ValidationException::withMessages([
                    'administered_date' => 'This draft is not from today. Record it as historical or external without an Inventory link; its administration date must be preserved.',
                ]);
            }

            $caseToFinish = null;
            if ($usesInventory && $record->clinic_appointment_id !== null) {
                $case = ClinicAppointment::query()->whereKey($record->clinic_appointment_id)->lockForUpdate()->first();
                if (! $case || (int) $case->pet_id !== $petId
                    || ! in_array($case->status, ClinicAppointment::CLINICAL_CONTENT_EDITABLE_STATUSES, true)) {
                    return $this->conflict('This case is already closed or is not eligible for vaccination.');
                }
                if ($finishCase) {
                    $case->assertCompletionVitals();
                    $caseToFinish = $case;
                }
            } elseif ($finishCase) {
                throw ValidationException::withMessages(['finish_case' => 'Select an ongoing Clinic case before finishing it.']);
            }

            if ($usesInventory && ($record->dose_amount === null || (float) $record->dose_amount <= 0 || blank($record->dose_unit))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add the dose amount and unit before finalizing this record.',
                    'errors' => [
                        'dose_amount' => ['Dose amount and unit are required.'],
                    ],
                ], 422);
            }

            if ($usesInventory) {
                $item = InventoryItem::query()
                    ->whereKey($record->inventory_item_id)
                    ->lockForUpdate()
                    ->first();
                if (! $item || ! $item->is_active || $item->category !== 'vaccine') {
                    throw ValidationException::withMessages(['inventory_item_id' => 'The selected Inventory item must still be an active vaccine.']);
                }
                if (! $this->supportsSingleDoseDeduction($item)) {
                    throw ValidationException::withMessages([
                        'inventory_item_id' => 'Automatic deduction requires an explicitly single-dose Inventory unit (dose, single-dose vial, or single-dose syringe). Measured units and generic bottles or vials cannot be deducted safely.',
                    ]);
                }
                if (! $record->administered_by_user_id
                    || ! $this->eligibleProviders($request->user())->whereKey($record->administered_by_user_id)->exists()) {
                    throw ValidationException::withMessages(['administered_by_user_id' => 'Choose yourself or an active veterinarian account.']);
                }
                $balance = $this->batchBalances->forItem((int) $item->item_id);
                $batch = $this->batchBalances->nextAvailableBatch($balance);

                if (! $batch) {
                    $vaccineName = $item?->item_name ?? $record->vaccine_name;

                    return response()->json([
                        'success' => false,
                        'message' => "No unexpired stock left for {$vaccineName}. Choose another vaccine.",
                        'errors' => [
                            'inventory_item_id' => ["No unexpired stock left for {$vaccineName}."],
                        ],
                    ], 422);
                }
                if ((float) $item->quantity_on_hand < 1 || (float) $balance['unexpired_quantity'] < 1
                    || (float) $batch['remaining_quantity'] < 1) {
                    throw ValidationException::withMessages(['inventory_item_id' => 'At least one complete single-dose unit must be available in the next unexpired batch.']);
                }

                $this->stockMovements->remove($request->user(), [[
                    'item_id' => $item->item_id,
                    'quantity' => 1,
                    'unit_cost_at_time' => $item->unit_cost,
                    'reason' => 'used',
                    'batch_number' => $batch['batch_number'],
                    'expiry_date' => $batch['expiry_date'],
                    'notes' => "Vaccination · {$pet->pet_name}",
                    'reference_type' => 'clinic',
                    'reference_id' => $record->clinic_appointment_id,
                ]]);

                $record->forceFill([
                    'batch_number' => $batch['batch_number'],
                    'product_expiry_date' => $batch['expiry_date'],
                ]);
            }

            $record->forceFill([
                'published_at' => now(),
                'published_by_user_id' => $request->user()->user_id,
            ])->save();

            $caseToFinish?->markCompleted();

            return response()->json([
                'success' => true,
                'message' => 'Vaccination record published.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    public function void(Request $request, int $petId, int $vaccinationId)
    {
        $this->findPet($petId);

        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
            $record = $this->findScopedRecord($petId, $vaccinationId, lockForUpdate: true);

            $validated = $request->validate([
                'void_reason' => ['required', 'string', 'max:500'],
                'pet_id' => ['prohibited'],
                'recorded_by_user_id' => ['prohibited'],
                'published_at' => ['prohibited'],
                'published_by_user_id' => ['prohibited'],
                'voided_at' => ['prohibited'],
                'voided_by_user_id' => ['prohibited'],
            ]);

            if ($record->published_at === null) {
                return $this->conflict('Draft vaccination records cannot use the published-record void workflow.');
            }

            if ($record->voided_at !== null) {
                return $this->conflict('This vaccination record has already been voided.');
            }

            $record->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => $request->user()->user_id,
                'void_reason' => $validated['void_reason'],
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Vaccination record voided.',
                'vaccination' => $this->formatRecord($this->loadResponseRelations($record)),
            ]);
        });
    }

    private function validateClinicalData(
        Request $request,
        int $petId,
        ?VaccinationRecord $record = null,
    ): array {
        $updating = $record !== null;
        $optional = $updating ? ['sometimes', 'nullable'] : ['nullable'];
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $usesInventory = filled($request->input('inventory_item_id', $record?->inventory_item_id));

        $validator = Validator::make($request->all(), [
            'next_due_date' => [...$optional, 'date', ...($usesInventory ? ['after_or_equal:today'] : [])],
            'dose_amount' => [...($usesInventory ? $required : $optional), 'numeric', 'gt:0', 'max:99999.999'],
            'dose_unit' => [...($usesInventory ? $required : $optional), 'string', 'max:30'],
            'route' => [...$optional, Rule::in(VaccinationRecord::ADMINISTRATION_ROUTES)],
            'administration_site' => [...$optional, 'string', 'max:100'],
            'administered_by_user_id' => [
                ...($usesInventory ? $required : $optional),
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $this->eligibleProviders($request->user())->whereKey($value)->exists()) {
                        $fail('Choose yourself or an active veterinarian account.');
                    }
                },
            ],
            'clinic_appointment_id' => [
                ...$optional,
                'integer',
                Rule::exists('clinic_appointments', 'id')
                    ->where(fn ($query) => $query->where('pet_id', $petId)),
            ],
            'inventory_item_id' => [
                ...($usesInventory ? $required : $optional),
                'integer',
                Rule::exists('inventory_items', 'item_id')
                    ->where(fn ($query) => $query->where('category', 'vaccine')->where('is_active', 1)),
            ],
            'notes' => [...$optional, 'string'],
            ...$this->serverManagedRules(),
            ...($usesInventory ? $this->systemFilledRules() : [
                'vaccine_name' => [...$required, 'string', 'max:150'],
                'administered_date' => [...$required, 'date', 'before_or_equal:today'],
                'product_name' => [...$optional, 'string', 'max:150'],
                'manufacturer' => [...$optional, 'string', 'max:150'],
                'batch_number' => [...$optional, 'string', 'max:100'],
                'product_expiry_date' => [...$optional, 'date'],
                'administered_by_name' => [...$optional, 'string', 'max:200'],
            ]),
        ], [
            'next_due_date.after_or_equal' => 'The next vaccination date cannot be before today.',
            'dose_amount.required' => 'Dose amount is required.',
            'dose_amount.gt' => 'Dose amount must be greater than zero.',
            'dose_amount.max' => 'Dose amount must not exceed 99,999.999.',
            'dose_unit.required' => 'Dose unit is required.',
        ]);

        $data = $validator->validate();
        if (! $usesInventory) {
            $administered = $data['administered_date'] ?? $record?->administered_date?->toDateString();
            foreach (['next_due_date', 'product_expiry_date'] as $field) {
                $later = array_key_exists($field, $data) ? $data[$field] : $record?->{$field}?->toDateString();
                if ($administered && $later && CarbonImmutable::parse($later)->lt(CarbonImmutable::parse($administered))) {
                    throw ValidationException::withMessages([$field => 'This date must be on or after the administration date.']);
                }
            }
        } elseif (! $updating) {
            $data['administered_date'] = now()->toDateString();
        }

        return $data;
    }

    private function serverManagedRules(): array
    {
        return [
            'id' => ['prohibited'],
            'pet_id' => ['prohibited'],
            'recorded_by_user_id' => ['prohibited'],
            'published_at' => ['prohibited'],
            'published_by_user_id' => ['prohibited'],
            'voided_at' => ['prohibited'],
            'voided_by_user_id' => ['prohibited'],
            'void_reason' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    // Current Inventory vaccinations use server-derived facts; historical records retain their facts.
    private function systemFilledRules(): array
    {
        return [
            'administered_date' => ['prohibited'],
            'vaccine_name' => ['prohibited'],
            'product_name' => ['prohibited'],
            'manufacturer' => ['prohibited'],
            'batch_number' => ['prohibited'],
            'product_expiry_date' => ['prohibited'],
            'administered_by_name' => ['prohibited'],
        ];
    }

    // The logged-in account, or any active veterinarian, may be recorded as the provider.
    private function eligibleProviders(User $actor): Builder
    {
        return User::query()->where(function (Builder $query) use ($actor): void {
            $query->whereKey($actor->user_id)
                ->orWhere(fn (Builder $vet) => $vet
                    ->where('role', 'staff')
                    ->where('staff_subrole', 'veterinarian')
                    ->where('is_active', 1));
        });
    }

    private function supportsSingleDoseDeduction(InventoryItem $item): bool
    {
        return in_array(strtolower(trim($item->unit)), ['dose', 'single-dose vial', 'single-dose syringe'], true);
    }

    // Drafts show the batch FEFO would use now; publish() fixes the batch actually consumed.
    private function addInventorySnapshot(array $data): array
    {
        if (empty($data['inventory_item_id'])) {
            return $data;
        }

        $item = InventoryItem::query()->findOrFail($data['inventory_item_id']);
        $batch = $this->batchBalances->nextAvailableBatch(
            $this->batchBalances->forItem((int) $item->item_id),
        );

        return [
            ...$data,
            'vaccine_name' => $item->item_name,
            'product_name' => null,
            'manufacturer' => null,
            'batch_number' => $batch['batch_number'] ?? null,
            'product_expiry_date' => $batch['expiry_date'] ?? null,
        ];
    }

    private function addProviderSnapshot(array $data): array
    {
        if (! empty($data['administered_by_user_id']) && empty($data['administered_by_name'])) {
            $provider = User::query()->findOrFail($data['administered_by_user_id']);
            $data['administered_by_name'] = $this->formatUserName($provider);
        }

        return $data;
    }

    private function findPet(int $petId): Pet
    {
        $pet = Pet::query()->find($petId);

        if (! $pet) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404));
        }

        return $pet;
    }

    private function findScopedRecord(
        int $petId,
        int $vaccinationId,
        bool $lockForUpdate = false,
    ): VaccinationRecord {
        $query = VaccinationRecord::query()
            ->where('pet_id', $petId)
            ->whereKey($vaccinationId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        if (! $record) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Vaccination record not found.',
            ], 404));
        }

        return $record;
    }

    private function loadResponseRelations(VaccinationRecord $record): VaccinationRecord
    {
        return $record->fresh(self::RESPONSE_RELATIONS);
    }

    private function formatPet(Pet $pet): array
    {
        return [
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
            'species' => $pet->species,
        ];
    }

    private function formatRecord(VaccinationRecord $record): array
    {
        return [
            'id' => $record->id,
            'pet_id' => $record->pet_id,
            'clinic_appointment_id' => $record->clinic_appointment_id,
            'clinic_appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'inventory_item_id' => $record->inventory_item_id,
            'inventory_item_name' => $record->inventoryItem?->item_name,
            'inventory_item_category' => $record->inventoryItem?->category,
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
            'administered_by_user_id' => $record->administered_by_user_id,
            'administered_by_name' => $record->administered_by_name,
            'administered_by_user_name' => $this->formatUserName($record->administeredBy),
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_by_user_name' => $this->formatUserName($record->recordedBy),
            'notes' => $record->notes,
            'state' => $this->recordState($record),
            'due_status' => $record->dueStatus(),
            'published_at' => $record->published_at?->toIso8601String(),
            'published_by_user_id' => $record->published_by_user_id,
            'published_by_user_name' => $this->formatUserName($record->publishedBy),
            'voided_at' => $record->voided_at?->toIso8601String(),
            'voided_by_user_id' => $record->voided_by_user_id,
            'voided_by_user_name' => $this->formatUserName($record->voidedBy),
            'void_reason' => $record->void_reason,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    private function formatUserName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function recordState(VaccinationRecord $record): string
    {
        if ($record->voided_at !== null) {
            return 'voided';
        }

        return $record->published_at !== null ? 'published' : 'draft';
    }

    private function conflict(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }
}
