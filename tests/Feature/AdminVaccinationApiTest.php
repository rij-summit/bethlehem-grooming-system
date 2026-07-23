<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminVaccinationController;
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
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name');
            $table->string('category');
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
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

        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_unauthenticated_requests_receive_unauthorized_responses(): void
    {
        $this->getJson('/api/admin/pets/101/vaccinations')->assertUnauthorized();
        $this->postJson('/api/admin/pets/101/vaccinations', [
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-07-23',
        ])->assertUnauthorized();
    }

    public function test_customer_is_forbidden_from_every_staff_vaccination_action(): void
    {
        $this->authenticateAs('customer', 10);

        $requests = [
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
        $this->authenticateAs($role, $userId);
        $this->insertPet(101, 'Mochi', 'cat');

        $this->postJson('/api/admin/pets/101/vaccinations', [
            'vaccine_name' => 'FVRCP',
            'administered_date' => '2026-07-22',
            'administered_by_name' => 'Dr. Rivera',
        ])
            ->assertCreated()
            ->assertJsonPath('vaccination.state', 'draft')
            ->assertJsonPath('vaccination.recorded_by_user_id', $userId)
            ->assertJsonPath('vaccination.published_at', null)
            ->assertJsonPath('vaccination.voided_at', null);

        $this->getJson('/api/admin/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonCount(1, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'FVRCP');
    }

    public static function authorizedRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
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

    public function test_draft_creation_sets_server_audit_data_snapshots_provider_and_does_not_change_stock(): void
    {
        Carbon::setTestNow('2026-07-23 09:30:00');
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Ana', 'Santos', 'admin');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 12.5);

        $response = $this->postJson('/api/admin/pets/101/vaccinations', [
            'clinic_appointment_id' => 201,
            'inventory_item_id' => 301,
            'vaccine_name' => 'Rabies',
            'product_name' => 'Rabies Shield',
            'manufacturer' => 'Example Labs',
            'batch_number' => 'LOT-101',
            'administered_date' => '2026-07-23',
            'next_due_date' => '2027-07-23',
            'product_expiry_date' => '2027-12-01',
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
        $this->assertSame('12.5', (string) DB::table('inventory_items')
            ->where('item_id', 301)
            ->value('quantity_on_hand'));

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
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-07-23',
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

    public function test_invalid_pet_appointment_inventory_and_provider_references_are_rejected(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertUser(4, 'Customer', 'Provider', 'customer');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $this->insertAppointment(202, 102);
        $this->insertInventoryItem(301, 'Antibiotic', 'medicine', 20);

        $valid = [
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-07-23',
        ];

        $this->postJson('/api/admin/pets/999/vaccinations', $valid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');

        foreach ([
            ['clinic_appointment_id', 999],
            ['clinic_appointment_id', 202],
            ['inventory_item_id', 999],
            ['inventory_item_id', 301],
            ['administered_by_user_id', 999],
            ['administered_by_user_id', 4],
        ] as [$field, $value]) {
            $this->postJson('/api/admin/pets/101/vaccinations', [
                ...$valid,
                $field => $value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('vaccination_records', 0);
        $this->assertSame('20', (string) DB::table('inventory_items')
            ->where('item_id', 301)
            ->value('quantity_on_hand'));
    }

    public function test_clinical_field_and_date_sequence_validation_is_enforced(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');

        $this->postJson('/api/admin/pets/101/vaccinations', [
            'vaccine_name' => str_repeat('V', 151),
            'product_name' => str_repeat('P', 151),
            'manufacturer' => str_repeat('M', 151),
            'batch_number' => str_repeat('B', 101),
            'administered_date' => '2026-07-23',
            'next_due_date' => '2026-07-22',
            'product_expiry_date' => '2026-07-21',
            'dose_amount' => 0,
            'dose_unit' => str_repeat('m', 31),
            'route' => 'unsupported',
            'administration_site' => str_repeat('S', 101),
            'administered_by_name' => str_repeat('N', 201),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'vaccine_name',
                'product_name',
                'manufacturer',
                'batch_number',
                'next_due_date',
                'product_expiry_date',
                'dose_amount',
                'dose_unit',
                'route',
                'administration_site',
                'administered_by_name',
            ]);

        $this->postJson('/api/admin/pets/101/vaccinations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vaccine_name', 'administered_date']);
    }

    public function test_staff_can_update_a_draft_but_not_move_it_or_edit_published_facts(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $draftId = $this->insertVaccination(101, 'Draft Name', '2026-07-23', [
            'next_due_date' => '2026-08-23',
            'recorded_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'vaccine_name' => 'Corrected Draft Name',
            'administered_by_name' => 'Dr. Rivera',
            'notes' => 'Updated while still a draft.',
        ])
            ->assertOk()
            ->assertJsonPath('vaccination.vaccine_name', 'Corrected Draft Name')
            ->assertJsonPath('vaccination.state', 'draft');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'pet_id' => 102,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_id');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'administered_date' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_due_date');

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

    public function test_valid_drafts_publish_with_either_provider_form_and_server_audit_values(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('admin', 1);
        $this->insertUser(2, 'Staff', 'Provider', 'staff');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 8);

        $nameProviderId = $this->insertVaccination(101, 'Rabies', '2026-07-23', [
            'inventory_item_id' => 301,
            'administered_by_name' => 'External Dr. Rivera',
            'recorded_by_user_id' => 1,
        ]);
        $userProviderId = $this->insertVaccination(101, 'FVRCP', '2026-07-22', [
            'inventory_item_id' => 301,
            'administered_by_user_id' => 2,
            'recorded_by_user_id' => 1,
        ]);

        $this->postJson("/api/admin/pets/101/vaccinations/{$nameProviderId}/publish", [
            'published_at' => '2020-01-01 00:00:00',
            'published_by_user_id' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['published_at', 'published_by_user_id']);

        foreach ([$nameProviderId, $userProviderId] as $recordId) {
            $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish")
                ->assertOk()
                ->assertJsonPath('vaccination.state', 'published')
                ->assertJsonPath('vaccination.published_by_user_id', 1)
                ->assertJsonPath('vaccination.published_at', '2026-07-23T14:15:00+08:00');
        }

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $nameProviderId,
            'published_by_user_id' => 1,
            'published_at' => '2026-07-23 14:15:00',
        ]);
        $this->assertDatabaseHas('vaccination_records', [
            'id' => $userProviderId,
            'published_by_user_id' => 1,
            'published_at' => '2026-07-23 14:15:00',
        ]);
        $this->assertSame('8', (string) DB::table('inventory_items')
            ->where('item_id', 301)
            ->value('quantity_on_hand'));

        $this->postJson("/api/admin/pets/101/vaccinations/{$nameProviderId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This vaccination record has already been published.');
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

        $this->assertCount(6, $controllerRoutes);
        $this->assertFalse($routes->contains(
            fn (RoutingRoute $route) => $route->uri() === 'api/pets/{petId}/vaccinations',
        ));
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
    ): void {
        DB::table('users')->updateOrInsert(
            ['user_id' => $userId],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
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

    private function insertAppointment(int $appointmentId, int $petId): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => $appointmentId,
            'pet_id' => $petId,
            'appointment_reference' => "CL-{$appointmentId}",
        ]);
    }

    private function insertInventoryItem(
        int $itemId,
        string $name,
        string $category,
        float $quantity,
    ): void {
        DB::table('inventory_items')->insert([
            'item_id' => $itemId,
            'item_name' => $name,
            'category' => $category,
            'quantity_on_hand' => $quantity,
        ]);
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
