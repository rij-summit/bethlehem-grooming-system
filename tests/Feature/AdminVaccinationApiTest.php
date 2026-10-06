<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminVaccinationController;
use App\Http\Controllers\PetVaccinationController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminVaccinationApiTest extends TestCase
{
    private $vaccinationMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role');
            $table->string('staff_subrole')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference');
            $table->string('status')->default('in_consultation');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->timestamp('consultation_finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->nullable();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name');
            $table->string('category');
            $table->string('unit')->default('vial');
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference_type', 30)->default('manual');
            $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->vaccinationMigration = require base_path(
            'database/migrations/2026_07_23_000004_create_vaccination_records_table.php',
        );
        $this->vaccinationMigration->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (Schema::hasTable('vaccination_records')) {
            $this->vaccinationMigration->down();
        }

        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_unauthenticated_requests_receive_unauthorized_responses(): void
    {
        $this->getJson('/api/admin/vaccination-options')->assertUnauthorized();
        $this->getJson('/api/admin/pets/101/vaccinations')->assertUnauthorized();
        $this->postJson('/api/admin/pets/101/vaccinations', [
            'inventory_item_id' => 301,
            'administered_date' => '2026-07-23',
        ])->assertUnauthorized();
    }

    public function test_customer_is_forbidden_from_every_staff_vaccination_action(): void
    {
        $this->authenticateAs('customer', 10);

        $requests = [
            fn () => $this->getJson('/api/admin/vaccination-options'),
            fn () => $this->getJson('/api/admin/pets/101/vaccinations'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations'),
            fn () => $this->getJson('/api/admin/pets/101/vaccinations/1'),
            fn () => $this->patchJson('/api/admin/pets/101/vaccinations/1'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations/1/publish'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations/1/void'),
        ];

        foreach ($requests as $request) {
            $request()
                ->assertForbidden()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Forbidden. You do not have permission to access this resource.',
                ]);
        }
    }

    #[DataProvider('authorizedRoles')]
    public function test_staff_and_admin_can_create_and_list_vaccination_drafts(string $role): void
    {
        $userId = $role === 'admin' ? 1 : 2;
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs($role, $userId);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'FVRCP Vaccine', 'vaccine', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', $this->validDraft(301, $userId))
            ->assertCreated()
            ->assertJsonPath('vaccination.state', 'draft')
            ->assertJsonPath('vaccination.vaccine_name', 'FVRCP Vaccine')
            ->assertJsonPath('vaccination.administered_date', '2026-07-23')
            ->assertJsonPath('vaccination.recorded_by_user_id', $userId)
            ->assertJsonPath('vaccination.published_at', null)
            ->assertJsonPath('vaccination.voided_at', null);

        $this->getJson('/api/admin/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonCount(1, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'FVRCP Vaccine');
    }

    public static function authorizedRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    public function test_options_list_clinic_vaccine_stock_and_eligible_veterinarians(): void
    {
        Carbon::setTestNow('2026-07-23 09:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Ana', 'Santos', 'staff', 'veterinarian');
        $this->insertUser(4, 'Ben', 'Cruz', 'staff', 'veterinarian', false);
        $this->insertUser(5, 'Grace', 'Reyes', 'staff', 'clinic_receptionist');
        $this->insertUser(6, 'Owner', 'Admin', 'admin');
        $this->insertRabiesStock(301);
        $this->insertInventoryItem(302, 'Parvo Vaccine', 'vaccine', 0);
        $this->insertInventoryItem(303, 'Retired Vaccine', 'vaccine', 0, ['is_active' => false]);
        $this->insertInventoryItem(304, 'Antibiotic', 'medicine', 10);

        $this->getJson('/api/admin/vaccination-options')
            ->assertOk()
            ->assertJsonCount(2, 'vaccines')
            ->assertJsonPath('vaccines.0.item_name', 'Parvo Vaccine')
            ->assertJsonPath('vaccines.0.available_quantity', 0)
            ->assertJsonPath('vaccines.0.next_batch', null)
            ->assertJsonPath('vaccines.1.item_name', 'Rabies Vaccine')
            ->assertJsonPath('vaccines.1.unit', 'vial')
            ->assertJsonPath('vaccines.1.available_quantity', 5)
            ->assertJsonPath('vaccines.1.next_batch.batch_number', 'LOT-EARLY')
            ->assertJsonPath('vaccines.1.next_batch.expiry_date', '2026-12-01')
            ->assertJsonCount(2, 'veterinarians')
            ->assertJsonPath('veterinarians.0.user_id', 2)
            ->assertJsonPath('veterinarians.0.is_current', true)
            ->assertJsonPath('veterinarians.1.user_id', 3)
            ->assertJsonPath('veterinarians.1.name', 'Ana Santos')
            ->assertJsonPath('veterinarians.1.is_current', false)
            ->assertJsonPath('current_user_id', 2)
            ->assertJsonPath('can_manage_staff', false);

        $this->authenticateAs('admin', 6);
        $this->getJson('/api/admin/vaccination-options')
            ->assertOk()
            ->assertJsonPath('can_manage_staff', true);
    }

    public function test_listing_is_pet_scoped_sorted_and_includes_each_state_and_due_status(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');

        $this->insertVaccination(101, 'Older Draft', '2026-05-01');
        $this->insertVaccination(101, 'Published', '2026-07-20', [
            'next_due_date' => '2026-08-01',
            'administered_by_name' => 'Dr. Rivera',
            'published_at' => '2026-07-20 10:00:00',
            'published_by_user_id' => 2,
        ]);
        $this->insertVaccination(101, 'Voided', '2026-07-22', [
            'administered_by_name' => 'Dr. Rivera',
            'published_at' => '2026-07-22 10:00:00',
            'published_by_user_id' => 2,
            'voided_at' => '2026-07-22 11:00:00',
            'voided_by_user_id' => 2,
            'void_reason' => 'Duplicate.',
        ]);
        $otherPetRecord = $this->insertVaccination(102, 'Other Pet', '2026-07-23');

        $response = $this->getJson('/api/admin/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonCount(3, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Voided')
            ->assertJsonPath('vaccinations.0.state', 'voided')
            ->assertJsonPath('vaccinations.1.vaccine_name', 'Published')
            ->assertJsonPath('vaccinations.1.state', 'published')
            ->assertJsonPath('vaccinations.1.due_status', 'due_soon')
            ->assertJsonPath('vaccinations.2.state', 'draft');

        $this->assertNotContains(
            $otherPetRecord,
            collect($response->json('vaccinations'))->pluck('id')->all(),
        );
    }

    public function test_vaccination_under_the_wrong_pet_returns_the_generic_not_found_response(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $recordId = $this->insertVaccination(102, 'Rabies', '2026-07-23');

        $this->getJson("/api/admin/pets/101/vaccinations/{$recordId}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Vaccination record not found.',
            ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$recordId}", [
            'notes' => 'Must not update.',
        ])->assertNotFound();

        $this->assertDatabaseMissing('vaccination_records', [
            'id' => $recordId,
            'notes' => 'Must not update.',
        ]);
    }

    public function test_draft_snapshots_inventory_product_fefo_batch_and_provider_without_changing_stock(): void
    {
        Carbon::setTestNow('2026-07-23 09:30:00');
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Ana', 'Santos', 'staff', 'veterinarian');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertRabiesStock(301);

        $response = $this->postJson('/api/admin/pets/101/vaccinations', [
            'clinic_appointment_id' => 201,
            'inventory_item_id' => 301,
            'next_due_date' => '2027-07-23',
            'dose_amount' => 1,
            'dose_unit' => 'mL',
            'route' => 'subcutaneous',
            'administration_site' => 'Right shoulder',
            'administered_by_user_id' => 3,
            'notes' => 'Initial dose.',
        ])
            ->assertCreated()
            ->assertJsonPath('vaccination.pet_id', 101)
            ->assertJsonPath('vaccination.recorded_by_user_id', 2)
            ->assertJsonPath('vaccination.vaccine_name', 'Rabies Vaccine')
            ->assertJsonPath('vaccination.administered_date', '2026-07-23')
            ->assertJsonPath('vaccination.dose_unit', 'mL')
            ->assertJsonPath('vaccination.product_name', null)
            ->assertJsonPath('vaccination.manufacturer', null)
            ->assertJsonPath('vaccination.batch_number', 'LOT-EARLY')
            ->assertJsonPath('vaccination.product_expiry_date', '2026-12-01')
            ->assertJsonPath('vaccination.administered_by_user_id', 3)
            ->assertJsonPath('vaccination.administered_by_name', 'Ana Santos')
            ->assertJsonPath('vaccination.clinic_appointment_reference', 'CL-201')
            ->assertJsonPath('vaccination.inventory_item_name', 'Rabies Vaccine')
            ->assertJsonPath('vaccination.state', 'draft');

        $recordId = $response->json('vaccination.id');
        $this->assertDatabaseHas('vaccination_records', [
            'id' => $recordId,
            'pet_id' => 101,
            'recorded_by_user_id' => 2,
            'published_at' => null,
            'published_by_user_id' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ]);
        $this->assertStockOnHand(301, '6');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());

        $recordPayload = $response->json('vaccination');
        $this->assertArrayNotHasKey('password_hash', $recordPayload);
        $this->assertArrayNotHasKey('file_path', $recordPayload);
        $this->assertArrayNotHasKey('inventory_item', $recordPayload);
        $this->assertArrayNotHasKey('clinic_appointment', $recordPayload);
    }

    public function test_request_cannot_supply_server_managed_creation_or_update_fields(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Other', 'Admin', 'admin');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $serverFields = [
            'pet_id' => 999,
            'recorded_by_user_id' => 3,
            'published_at' => '2026-07-23 10:00:00',
            'published_by_user_id' => 3,
            'voided_at' => '2026-07-23 11:00:00',
            'voided_by_user_id' => 3,
            'void_reason' => 'Injected.',
        ];

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301, 2),
            ...$serverFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($serverFields));

        $recordId = $this->insertVaccination(101, 'Draft', '2026-07-23', [
            'recorded_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$recordId}", [
            'notes' => 'Injected update.',
            ...$serverFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($serverFields));

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $recordId,
            'pet_id' => 101,
            'recorded_by_user_id' => 2,
            'notes' => null,
        ]);
    }

    public function test_inventory_derived_and_free_text_provider_fields_cannot_be_typed_by_staff(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $typedFields = [
            'vaccine_name' => 'Rabies',
            'product_name' => 'Rabies Shield',
            'manufacturer' => 'Example Labs',
            'batch_number' => 'LOT-TYPED',
            'product_expiry_date' => '2027-12-01',
            'administered_by_name' => 'External Dr. Rivera',
        ];

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301, 2),
            ...$typedFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($typedFields));

        $draftId = $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-23', [
            'inventory_item_id' => 301,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", $typedFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($typedFields));

        $this->assertDatabaseCount('vaccination_records', 1);
    }

    public function test_invalid_pet_appointment_inventory_and_provider_references_are_rejected(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertUser(4, 'Customer', 'Provider', 'customer');
        $this->insertUser(5, 'Grace', 'Reyes', 'staff', 'clinic_receptionist');
        $this->insertUser(6, 'Ben', 'Cruz', 'staff', 'veterinarian', false);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $this->insertAppointment(202, 102);
        $this->insertInventoryItem(301, 'Antibiotic', 'medicine', 20);
        $this->insertInventoryItem(302, 'Rabies Vaccine', 'vaccine', 0);
        $this->insertInventoryItem(303, 'Retired Vaccine', 'vaccine', 0, ['is_active' => false]);

        $valid = $this->validDraft(302, 2);

        $this->postJson('/api/admin/pets/999/vaccinations', $valid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');

        foreach ([
            ['clinic_appointment_id', 999],
            ['clinic_appointment_id', 202],
            ['inventory_item_id', 999],
            ['inventory_item_id', 301],
            ['inventory_item_id', 303],
            ['administered_by_user_id', 999],
            ['administered_by_user_id', 4],
            ['administered_by_user_id', 5],
            ['administered_by_user_id', 6],
        ] as [$field, $value]) {
            $this->postJson('/api/admin/pets/101/vaccinations', [
                ...$valid,
                $field => $value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('vaccination_records', 0);
        $this->assertStockOnHand(301, '20');
    }

    public function test_new_drafts_can_only_be_linked_to_an_ongoing_case(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);
        $this->insertAppointment(201, 101, 'completed');
        $this->insertAppointment(202, 101, 'cancelled');
        $this->insertAppointment(203, 101, 'waiting_to_arrive');
        $this->insertAppointment(204, 101, 'in_consultation');

        $valid = $this->validDraft(301, 2);

        foreach ([201, 202, 203] as $closedCaseId) {
            $this->postJson('/api/admin/pets/101/vaccinations', [...$valid, 'clinic_appointment_id' => $closedCaseId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('clinic_appointment_id');
        }
        $this->assertDatabaseCount('vaccination_records', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', [...$valid, 'clinic_appointment_id' => 204])
            ->assertCreated();
    }

    public function test_clinical_field_and_date_sequence_validation_is_enforced(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301, 2),
            'next_due_date' => '2026-07-22',
            'dose_amount' => 0,
            'dose_unit' => str_repeat('m', 31),
            'route' => 'unsupported',
            'administration_site' => str_repeat('S', 101),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'next_due_date' => 'The next vaccination date cannot be before today.',
                'dose_amount' => 'Dose amount must be greater than zero.',
                'dose_unit',
                'route',
                'administration_site',
            ]);

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301, 2),
            'dose_amount' => 100000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dose_amount' => 'Dose amount must not exceed 99,999.999.']);

        $this->postJson('/api/admin/pets/101/vaccinations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'inventory_item_id',
                'administered_by_user_id',
                'dose_amount' => 'Dose amount is required.',
                'dose_unit' => 'Dose unit is required.',
            ]);

        $this->assertDatabaseCount('vaccination_records', 0);
    }

    public function test_administration_date_is_always_today_and_cannot_be_chosen(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        foreach (['2026-07-01', '2026-08-01'] as $chosenDate) {
            $this->postJson('/api/admin/pets/101/vaccinations', [
                ...$this->validDraft(301, 2),
                'administered_date' => $chosenDate,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('administered_date');
        }
        $this->assertDatabaseCount('vaccination_records', 0);

        $olderDraftId = $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-20', [
            'inventory_item_id' => 301,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$olderDraftId}", $this->validDraft(301, 2))
            ->assertOk()
            ->assertJsonPath('vaccination.administered_date', '2026-07-23');
    }

    public function test_staff_can_update_a_draft_but_not_move_it_or_edit_published_facts(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $draftId = $this->insertVaccination(101, 'Draft Name', '2026-07-23', [
            'next_due_date' => '2026-08-23',
            'recorded_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'administered_by_user_id' => 2,
            'notes' => 'Updated while still a draft.',
        ])
            ->assertOk()
            ->assertJsonPath('vaccination.administered_by_name', 'Staff User')
            ->assertJsonPath('vaccination.state', 'draft');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'pet_id' => 102,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_id');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'next_due_date' => '2026-07-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_due_date');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'dose_amount' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dose_amount');

        DB::table('vaccination_records')->where('id', $draftId)->update([
            'published_at' => now(),
            'published_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'notes' => 'Silent published edit.',
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Published or voided vaccination records cannot be edited. Void an incorrect published record and create a corrected replacement.',
            );

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $draftId,
            'pet_id' => 101,
            'notes' => 'Updated while still a draft.',
        ]);
    }

    public function test_publishing_deducts_one_unit_from_the_fefo_batch_and_records_a_clinic_transaction(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('admin', 1);
        $this->insertUser(2, 'Ana', 'Santos', 'staff', 'veterinarian');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertRabiesStock(301);
        $recordId = $this->insertFinalizableDraft(301, 201);

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish", [
            'published_at' => '2020-01-01 00:00:00',
            'published_by_user_id' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['published_at', 'published_by_user_id']);
        $this->assertStockOnHand(301, '6');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish")
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'published')
            ->assertJsonPath('vaccination.batch_number', 'LOT-EARLY')
            ->assertJsonPath('vaccination.product_expiry_date', '2026-12-01')
            ->assertJsonPath('vaccination.published_by_user_id', 1)
            ->assertJsonPath('vaccination.published_at', '2026-07-23T14:15:00+08:00');

        $this->assertStockOnHand(301, '5');
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => 301,
            'type' => 'stock_out',
            'quantity' => 1,
            'reason' => 'used',
            'reference_type' => 'clinic',
            'reference_id' => 201,
            'batch_number' => 'LOT-EARLY',
            'notes' => 'Vaccination · Mochi',
            'performed_by' => 1,
        ]);
        $this->assertStringStartsWith('2026-12-01', (string) DB::table('inventory_transactions')
            ->where('type', 'stock_out')
            ->value('expiry_date'));
        $this->assertSame('in_consultation', DB::table('clinic_appointments')->where('id', 201)->value('status'));

        $this->getJson('/api/inventory/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'stock_out')
            ->assertJsonPath('data.0.reason', 'used')
            ->assertJsonPath('data.0.batch_number', 'LOT-EARLY')
            ->assertJsonPath('data.0.source_label', 'Clinic · CL-201');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This vaccination record has already been published.');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/void", [
            'void_reason' => 'Recorded on the wrong pet.',
        ])->assertOk();

        $this->assertStockOnHand(301, '5');
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_finishing_the_case_completes_it_with_the_deduction_and_rejects_closed_cases(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertAppointment(202, 101, 'completed');
        $this->insertRabiesStock(301);
        $ongoingCaseRecord = $this->insertFinalizableDraft(301, 201);
        $closedCaseRecord = $this->insertFinalizableDraft(301, 202);

        $this->postJson("/api/admin/pets/101/vaccinations/{$ongoingCaseRecord}/publish", ['finish_case' => true])
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'published');

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 201,
            'status' => 'completed',
            'queue_number' => null,
            'consultation_finished_at' => '2026-07-23 14:15:00',
        ]);
        $this->assertStockOnHand(301, '5');

        $this->postJson("/api/admin/pets/101/vaccinations/{$closedCaseRecord}/publish", ['finish_case' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This case is already closed.');

        $this->assertStockOnHand(301, '5');
        $this->assertDatabaseHas('vaccination_records', ['id' => $closedCaseRecord, 'published_at' => null]);
    }

    public function test_publishing_without_unexpired_stock_or_inventory_link_changes_nothing(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 1);
        $this->insertStockIn(301, 1, 'LOT-OLD', '2026-07-01', '2026-06-01 08:00:00');
        $expiredOnlyRecord = $this->insertFinalizableDraft(301, null);
        $unlinkedRecord = $this->insertFinalizableDraft(null, null);

        $this->postJson("/api/admin/pets/101/vaccinations/{$expiredOnlyRecord}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No unexpired stock left for Rabies Vaccine. Choose another vaccine.')
            ->assertJsonValidationErrors('inventory_item_id');

        $this->postJson("/api/admin/pets/101/vaccinations/{$unlinkedRecord}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inventory_item_id');

        $doselessRecord = $this->insertFinalizableDraft(301, null);
        DB::table('vaccination_records')->where('id', $doselessRecord)->update(['dose_amount' => null]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$doselessRecord}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add the dose amount and unit before finalizing this record.')
            ->assertJsonValidationErrors('dose_amount');

        $this->assertStockOnHand(301, '1');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertSame(3, DB::table('vaccination_records')->whereNull('published_at')->count());
    }

    public function test_publish_rejects_incomplete_and_voided_records(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $providerlessId = $this->insertVaccination(101, 'Rabies', '2026-07-23');

        $this->postJson("/api/admin/pets/101/vaccinations/{$providerlessId}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The vaccination draft is not ready to publish.');

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $providerlessId,
            'published_at' => null,
        ]);

        $voidedId = $this->insertVaccination(101, 'FVRCP', '2026-07-22', [
            'administered_by_name' => 'Dr. Rivera',
            'published_at' => '2026-07-22 10:00:00',
            'published_by_user_id' => 2,
            'voided_at' => '2026-07-22 11:00:00',
            'voided_by_user_id' => 2,
            'void_reason' => 'Incorrect batch.',
        ]);

        $this->postJson("/api/admin/pets/101/vaccinations/{$voidedId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A voided vaccination record cannot be published again.');
    }

    public function test_published_record_can_be_voided_but_draft_or_empty_reason_cannot(): void
    {
        Carbon::setTestNow('2026-07-23 15:30:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $publishedId = $this->insertVaccination(101, 'Rabies', '2026-07-23', [
            'administered_by_name' => 'Dr. Rivera',
            'published_at' => '2026-07-23 14:00:00',
            'published_by_user_id' => 2,
        ]);
        $draftId = $this->insertVaccination(101, 'FVRCP', '2026-07-23');

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('void_reason');

        $this->postJson("/api/admin/pets/101/vaccinations/{$draftId}/void", [
            'void_reason' => 'Draft correction.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Draft vaccination records cannot use the published-record void workflow.');

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => 'Injected audit values.',
            'voided_at' => '2020-01-01 00:00:00',
            'voided_by_user_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voided_at', 'voided_by_user_id']);

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => 'Wrong product was recorded.',
        ])
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'voided')
            ->assertJsonPath('vaccination.voided_by_user_id', 2)
            ->assertJsonPath('vaccination.voided_at', '2026-07-23T15:30:00+08:00')
            ->assertJsonPath('vaccination.void_reason', 'Wrong product was recorded.');

        $this->assertDatabaseCount('vaccination_records', 2);
        $this->assertDatabaseHas('vaccination_records', [
            'id' => $publishedId,
            'voided_by_user_id' => 2,
            'voided_at' => '2026-07-23 15:30:00',
            'void_reason' => 'Wrong product was recorded.',
        ]);
    }

    public function test_every_staff_vaccination_route_has_authentication_and_role_middleware(): void
    {
        $expected = [
            ['GET', 'api/admin/vaccination-options'],
            ['GET', 'api/admin/pets/{petId}/vaccinations'],
            ['POST', 'api/admin/pets/{petId}/vaccinations'],
            ['GET', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}'],
            ['PATCH', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}'],
            ['POST', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}/publish'],
            ['POST', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}/void'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected vaccination route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        $controllerRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with(
                $route->getActionName(),
                AdminVaccinationController::class.'@',
            ),
        );

        $this->assertCount(7, $controllerRoutes);

        $clientRoute = $routes->first(
            fn (RoutingRoute $route) => $route->uri() === 'api/pets/{petId}/vaccinations'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertNotNull($clientRoute);
        $this->assertSame(
            PetVaccinationController::class.'@index',
            $clientRoute->getActionName(),
        );
        $this->assertContains('auth:sanctum', $clientRoute->gatherMiddleware());
        $this->assertNotContains('role:admin,staff', $clientRoute->gatherMiddleware());
    }

    private function authenticateAs(string $role, int $userId): void
    {
        $this->insertUser($userId, ucfirst($role), 'User', $role);

        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function insertUser(
        int $userId,
        string $firstName,
        string $lastName,
        string $role,
        ?string $staffSubrole = null,
        bool $isActive = true,
    ): void {
        DB::table('users')->updateOrInsert(
            ['user_id' => $userId],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'staff_subrole' => $staffSubrole,
                'is_active' => $isActive,
            ],
        );
    }

    private function insertPet(int $petId, string $name, string $species): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'pet_name' => $name,
            'species' => $species,
        ]);
    }

    private function insertAppointment(int $appointmentId, int $petId, string $status = 'in_consultation'): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => $appointmentId,
            'pet_id' => $petId,
            'appointment_reference' => "CL-{$appointmentId}",
            'status' => $status,
            'queue_number' => 3,
        ]);
    }

    private function insertInventoryItem(
        int $itemId,
        string $name,
        string $category,
        float $quantity,
        array $overrides = [],
    ): void {
        DB::table('inventory_items')->insert([
            'item_id' => $itemId,
            'item_name' => $name,
            'category' => $category,
            'quantity_on_hand' => $quantity,
            ...$overrides,
        ]);
    }

    private function insertStockIn(
        int $itemId,
        float $quantity,
        string $batchNumber,
        string $expiryDate,
        string $receivedAt,
    ): void {
        DB::table('inventory_transactions')->insert([
            'item_id' => $itemId,
            'type' => 'stock_in',
            'quantity' => $quantity,
            'reason' => 'purchase',
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'created_at' => $receivedAt,
        ]);
    }

    // 6 vials: an expired batch, a later-expiring batch received first, and the FEFO batch.
    private function insertRabiesStock(int $itemId): void
    {
        $this->insertInventoryItem($itemId, 'Rabies Vaccine', 'vaccine', 6, ['unit_cost' => 150]);
        $this->insertStockIn($itemId, 1, 'LOT-OLD', '2026-07-01', '2026-06-01 08:00:00');
        $this->insertStockIn($itemId, 3, 'LOT-LATE', '2027-06-01', '2026-07-01 08:00:00');
        $this->insertStockIn($itemId, 2, 'LOT-EARLY', '2026-12-01', '2026-07-10 08:00:00');
    }

    private function insertFinalizableDraft(?int $itemId, ?int $appointmentId): int
    {
        return $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-23', [
            'inventory_item_id' => $itemId,
            'clinic_appointment_id' => $appointmentId,
            'administered_by_user_id' => 2,
            'administered_by_name' => 'Ana Santos',
            'dose_amount' => 1,
            'dose_unit' => 'mL',
        ]);
    }

    private function validDraft(int $itemId, int $providerId): array
    {
        return [
            'inventory_item_id' => $itemId,
            'administered_by_user_id' => $providerId,
            'dose_amount' => 1,
            'dose_unit' => 'mL',
        ];
    }

    private function assertStockOnHand(int $itemId, string $expected): void
    {
        $this->assertSame($expected, (string) DB::table('inventory_items')
            ->where('item_id', $itemId)
            ->value('quantity_on_hand'));
    }

    private function insertVaccination(
        int $petId,
        string $vaccineName,
        string $administeredDate,
        array $overrides = [],
    ): int {
        return DB::table('vaccination_records')->insertGetId([
            'pet_id' => $petId,
            'vaccine_name' => $vaccineName,
            'administered_date' => $administeredDate,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}
