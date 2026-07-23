<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;

class AdminVaccinationController extends Controller
{
    private const PROVIDER_ROLES = ['admin', 'staff'];

    private const RESPONSE_RELATIONS = [
        'clinicAppointment:id,appointment_reference',
        'inventoryItem:item_id,item_name,category',
        'administeredBy:user_id,first_name,last_name',
        'recordedBy:user_id,first_name,last_name',
        'publishedBy:user_id,first_name,last_name',
        'voidedBy:user_id,first_name,last_name',
    ];

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
        $data = $this->addProviderSnapshot($data);

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
        $this->findPet($petId);
        $request->validate($this->serverManagedRules());

        return DB::transaction(function () use ($request, $petId, $vaccinationId) {
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
                            'Vaccine name, administration date, and either a staff/admin provider or provider name are required.',
                        ],
                    ],
                ], 422);
            }

            $record->forceFill([
                'published_at' => now(),
                'published_by_user_id' => $request->user()->user_id,
            ])->save();

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

        $validator = Validator::make($request->all(), [
            'vaccine_name' => [...$required, 'string', 'max:150'],
            'product_name' => [...$optional, 'string', 'max:150'],
            'manufacturer' => [...$optional, 'string', 'max:150'],
            'batch_number' => [...$optional, 'string', 'max:100'],
            'administered_date' => [...$required, 'date'],
            'next_due_date' => [...$optional, 'date'],
            'product_expiry_date' => [...$optional, 'date'],
            'dose_amount' => [...$optional, 'numeric', 'gt:0'],
            'dose_unit' => [...$optional, 'string', 'max:30'],
            'route' => [...$optional, Rule::in(VaccinationRecord::ADMINISTRATION_ROUTES)],
            'administration_site' => [...$optional, 'string', 'max:100'],
            'administered_by_user_id' => [
                ...$optional,
                'integer',
                Rule::exists('users', 'user_id')
                    ->where(fn ($query) => $query->whereIn('role', self::PROVIDER_ROLES)),
            ],
            'administered_by_name' => [...$optional, 'string', 'max:200'],
            'clinic_appointment_id' => [
                ...$optional,
                'integer',
                Rule::exists('clinic_appointments', 'id')
                    ->where(fn ($query) => $query->where('pet_id', $petId)),
            ],
            'inventory_item_id' => [
                ...$optional,
                'integer',
                Rule::exists('inventory_items', 'item_id')
                    ->where(fn ($query) => $query->where('category', 'vaccine')),
            ],
            'notes' => [...$optional, 'string'],
            ...$this->serverManagedRules(),
        ]);

        $validator->after(function (LaravelValidator $validator) use ($request, $record): void {
            $this->validateDateSequence(
                $validator,
                $request,
                $record,
                'next_due_date',
                'The next due date must be on or after the administration date.',
            );
            $this->validateDateSequence(
                $validator,
                $request,
                $record,
                'product_expiry_date',
                'The product expiry date must be on or after the administration date.',
            );
        });

        return $validator->validate();
    }

    private function validateDateSequence(
        LaravelValidator $validator,
        Request $request,
        ?VaccinationRecord $record,
        string $laterDateField,
        string $message,
    ): void {
        if ($validator->errors()->has('administered_date') || $validator->errors()->has($laterDateField)) {
            return;
        }

        $administeredDate = $request->exists('administered_date')
            ? $request->input('administered_date')
            : $record?->administered_date?->toDateString();
        $laterDate = $request->exists($laterDateField)
            ? $request->input($laterDateField)
            : $record?->{$laterDateField}?->toDateString();

        if (
            filled($administeredDate)
            && filled($laterDate)
            && CarbonImmutable::parse($laterDate)->lt(CarbonImmutable::parse($administeredDate))
        ) {
            $validator->errors()->add($laterDateField, $message);
        }
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

    private function addProviderSnapshot(array $data): array
    {
        if (
            ! empty($data['administered_by_user_id'])
            && empty($data['administered_by_name'])
        ) {
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
