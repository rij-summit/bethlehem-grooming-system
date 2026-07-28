<?php

namespace Tests\Feature;

use App\Http\Controllers\PetGroomingMedicalConcernController;
use App\Models\GroomingMedicalConcern;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientGroomingMedicalConcernApiTest extends TestCase
{
    private const LIST_URI = '/api/pets/101/medical-concerns';

    private $bookingPetMigration;

    private $concernMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-25 10:00:00'));
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createExistingSchema();

        $this->bookingPetMigration = require base_path(
            'database/migrations/2026_07_24_000002_add_grooming_state_and_integrity_to_booking_pets.php',
        );
        $this->concernMigration = require base_path(
            'database/migrations/2026_07_24_000003_create_grooming_medical_concern_tables.php',
        );

        $this->bookingPetMigration->up();
        $this->concernMigration->up();

        $this->seedExistingData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::disableForeignKeyConstraints();

        foreach ([
            'grooming_medical_concern_responses',
            'grooming_medical_concerns',
            'clinic_appointments',
            'booking_pets',
            'bookings',
            'pets',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_list_and_detail_require_authentication(): void
    {
        $this->getJson(self::LIST_URI)->assertUnauthorized();
        $this->getJson(self::LIST_URI.'/00000000-0000-4000-8000-000000000000')
            ->assertUnauthorized();
    }

    public function test_owned_pet_is_available_while_foreign_and_missing_pets_are_indistinguishable(): void
    {
        $this->authenticateAs(10);

        $this->getJson(self::LIST_URI)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'concerns' => [],
            ]);

        $foreign = $this->getJson('/api/pets/202/medical-concerns')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $missing = $this->getJson('/api/pets/999/medical-concerns')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $this->assertSame($foreign->getContent(), $missing->getContent());
    }

    public function test_list_is_pet_scoped_newest_first_and_never_mixes_siblings_or_owners(): void
    {
        $this->authenticateAs(10);

        $older = $this->createConcern([
            'reported_at' => now()->subHours(2),
            'customer_message' => 'Older concern for Mochi.',
            'customer_notified_at' => now()->subHours(2),
        ]);
        $newer = $this->createConcern([
            'reported_at' => now()->subHour(),
            'customer_message' => 'Newer concern for Mochi.',
            'customer_notified_at' => now()->subHour(),
        ]);
        $sibling = $this->createConcern([
            'booking_pet_id' => 12,
            'pet_id' => 102,
            'customer_message' => 'Concern for sibling pet.',
            'customer_notified_at' => now(),
        ]);
        $foreign = $this->createConcern([
            'booking_id' => 2,
            'booking_pet_id' => 21,
            'pet_id' => 202,
            'customer_message' => 'Concern for another customer.',
            'customer_notified_at' => now(),
        ]);

        $response = $this->getJson(self::LIST_URI)
            ->assertOk()
            ->assertJsonCount(2, 'concerns')
            ->assertJsonPath('concerns.0.public_id', $newer->public_id)
            ->assertJsonPath('concerns.1.public_id', $older->public_id);

        $content = $response->getContent();
        $this->assertStringNotContainsString($sibling->public_id, $content);
        $this->assertStringNotContainsString($foreign->public_id, $content);
        $this->assertStringNotContainsString('Concern for sibling pet.', $content);
        $this->assertStringNotContainsString('Concern for another customer.', $content);
    }

    public function test_only_notified_customer_message_history_is_visible_for_every_lifecycle_state(): void
    {
        $this->authenticateAs(10);

        $active = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_OPEN,
            'customer_message' => 'Visible active concern.',
            'customer_notified_at' => now()->subMinutes(3),
        ]);
        $resolved = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'customer_message' => 'Visible resolved concern.',
            'customer_notified_at' => now()->subMinutes(2),
            'customer_resolution_summary' => 'The concern was resolved safely.',
            'resolved_at' => now()->subMinute(),
        ]);
        $cancelled = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_CANCELLED,
            'customer_message' => 'Visible cancelled concern.',
            'customer_notified_at' => now()->subMinute(),
            'customer_resolution_summary' => 'The record was corrected.',
            'resolved_at' => now(),
        ]);
        $unnotified = $this->createConcern([
            'customer_message' => 'Hidden unnotified staff concern.',
            'customer_notified_at' => null,
        ]);
        $blankMessage = $this->createConcern([
            'customer_message' => '   ',
            'customer_notified_at' => now(),
        ]);

        $response = $this->getJson(self::LIST_URI)
            ->assertOk()
            ->assertJsonCount(3, 'concerns');

        $publicIds = collect($response->json('concerns'))->pluck('public_id');
        $this->assertTrue($publicIds->contains($active->public_id));
        $this->assertTrue($publicIds->contains($resolved->public_id));
        $this->assertTrue($publicIds->contains($cancelled->public_id));
        $this->assertFalse($publicIds->contains($unnotified->public_id));
        $this->assertFalse($publicIds->contains($blankMessage->public_id));

        $this->getJson(self::LIST_URI.'/'.$unnotified->public_id)
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Medical concern not found.',
            ]);
    }

    public function test_detail_uses_public_id_and_wrong_pet_foreign_unnotified_and_missing_values_are_generic(): void
    {
        $this->authenticateAs(10);

        $owned = $this->createConcern([
            'customer_notified_at' => now(),
        ]);
        $sibling = $this->createConcern([
            'booking_pet_id' => 12,
            'pet_id' => 102,
            'customer_notified_at' => now(),
        ]);
        $foreign = $this->createConcern([
            'booking_id' => 2,
            'booking_pet_id' => 21,
            'pet_id' => 202,
            'customer_notified_at' => now(),
        ]);

        $this->getJson(self::LIST_URI.'/'.$owned->public_id)
            ->assertOk()
            ->assertJsonPath('concern.public_id', $owned->public_id);

        foreach ([
            $sibling->public_id,
            $foreign->public_id,
            '00000000-0000-4000-8000-000000000000',
            'not-a-public-id',
        ] as $hiddenPublicId) {
            $this->getJson(self::LIST_URI.'/'.$hiddenPublicId)
                ->assertNotFound()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Medical concern not found.',
                ]);
        }
    }

    public function test_detail_uses_an_exact_customer_safe_whitelist_and_friendly_labels(): void
    {
        $this->authenticateAs(10);

        $concern = $this->createConcern([
            'reported_at' => now()->subHour(),
            'severity' => GroomingMedicalConcern::SEVERITY_URGENT,
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'consent_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
            'clinic_appointment_id' => 500,
            'customer_resolution_summary' => 'Customer-safe summary.',
            'resolved_at' => now(),
            'internal_description' => 'PRIVATE INTERNAL DESCRIPTION',
            'internal_resolution_notes' => 'PRIVATE INTERNAL RESOLUTION',
            'reported_by_name' => 'PRIVATE REPORTER',
            'resolved_by_name' => 'PRIVATE RESOLVER',
            'report_token' => '10000000-0000-4000-8000-000000000001',
        ]);

        $response = $this->getJson(self::LIST_URI.'/'.$concern->public_id)
            ->assertOk()
            ->assertJsonPath('concern.pet_name', 'Mochi')
            ->assertJsonPath('concern.severity', 'urgent')
            ->assertJsonPath('concern.severity_label', 'Urgent')
            ->assertJsonPath('concern.recommended_grooming_action_label', 'Stop grooming')
            ->assertJsonPath('concern.applied_grooming_action_label', 'Pause grooming')
            ->assertJsonPath('concern.status_label', 'Awaiting customer')
            ->assertJsonPath('concern.customer_response_status_label', 'Pending')
            ->assertJsonPath('concern.clinic_appointment_reference', 'CL-500')
            ->assertJsonPath('concern.required_customer_action', 'consent')
            ->assertJsonPath('concern.required_customer_action_label', 'Provide consent');

        $record = $response->json('concern');
        $this->assertSame($this->expectedCustomerFields(), array_keys($record));

        foreach ($this->forbiddenCustomerFields() as $field) {
            $this->assertArrayNotHasKey($field, $record);
        }

        $content = $response->getContent();
        foreach ([
            'PRIVATE INTERNAL DESCRIPTION',
            'PRIVATE INTERNAL RESOLUTION',
            'PRIVATE REPORTER',
            'PRIVATE RESOLVER',
            '10000000-0000-4000-8000-000000000001',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }
    }

    public function test_required_customer_action_prioritizes_pending_consent_then_acknowledgment(): void
    {
        $this->authenticateAs(10);

        $cases = [
            [
                'message' => 'Consent case.',
                'acknowledgment_required' => true,
                'consent_required' => true,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'expected' => 'consent',
                'label' => 'Provide consent',
                'required' => true,
            ],
            [
                'message' => 'Acknowledgment case.',
                'acknowledgment_required' => true,
                'consent_required' => false,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'expected' => 'acknowledgment',
                'label' => 'Acknowledge',
                'required' => true,
            ],
            [
                'message' => 'Completed response case.',
                'acknowledgment_required' => true,
                'consent_required' => true,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_APPROVED,
                'expected' => 'none',
                'label' => 'None',
                'required' => false,
            ],
            [
                'message' => 'No requirement case.',
                'acknowledgment_required' => false,
                'consent_required' => false,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'expected' => 'none',
                'label' => 'None',
                'required' => false,
            ],
        ];

        foreach ($cases as $case) {
            $concern = $this->createConcern([
                'customer_message' => $case['message'],
                'acknowledgment_required' => $case['acknowledgment_required'],
                'consent_required' => $case['consent_required'],
                'customer_response_status' => $case['customer_response_status'],
                'customer_notified_at' => now(),
            ]);

            $this->getJson(self::LIST_URI.'/'.$concern->public_id)
                ->assertOk()
                ->assertJsonPath('concern.required_customer_action', $case['expected'])
                ->assertJsonPath('concern.required_customer_action_label', $case['label'])
                ->assertJsonPath('concern.customer_action_required', $case['required']);
        }
    }

    public function test_routes_are_read_only_owner_scoped_and_central_helpers_power_the_read_only_client_ui(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $customerConcernRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with(
                $route->getActionName(),
                PetGroomingMedicalConcernController::class.'@',
            ),
        );

        $this->assertCount(2, $customerConcernRoutes);
        $customerConcernRoutes->each(function (RoutingRoute $route): void {
            $this->assertContains('GET', $route->methods());
            $this->assertContains('HEAD', $route->methods());
            $this->assertNotContains('POST', $route->methods());
            $this->assertNotContains('PATCH', $route->methods());
            $this->assertNotContains('PUT', $route->methods());
            $this->assertNotContains('DELETE', $route->methods());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('role:admin,staff', $route->gatherMiddleware());
        });

        $apiLayer = file_get_contents(base_path('scripts/api.js'));
        foreach (['getPetMedicalConcerns', 'getPetMedicalConcern'] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $apiLayer,
            );
        }
        $this->assertStringContainsString(
            '`/pets/${encodeURIComponent(petId)}/medical-concerns`',
            $apiLayer,
        );
        $this->assertStringContainsString(
            '/medical-concerns/${encodeURIComponent(publicId)}`',
            $apiLayer,
        );

        $clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->assertStringContainsString('Medical-Concern Notifications', $clientPage);
        $this->assertStringContainsString('getPetMedicalConcerns', $clientComponent);
        $this->assertStringContainsString('getPetMedicalConcern', $clientComponent);
        $this->assertStringNotContainsString('submitConcernAcknowledgment', $clientComponent);
        $this->assertStringNotContainsString('submitConcernConsent', $clientComponent);
        $this->assertStringContainsString('data-pet-panel="grooming"', $clientPage);
        $this->assertStringContainsString('data-pet-panel="medical"', $clientPage);
        $this->assertStringContainsString('data-pet-panel="vaccinations"', $clientPage);
    }

    private function createExistingSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference')->unique();
        });
    }

    private function seedExistingData(): void
    {
        DB::table('users')->insert([
            [
                'user_id' => 10,
                'first_name' => 'Mochi',
                'last_name' => 'Owner',
                'email' => 'owner@example.test',
                'phone' => '09170000010',
                'role' => 'customer',
            ],
            [
                'user_id' => 20,
                'first_name' => 'Other',
                'last_name' => 'Owner',
                'email' => 'other@example.test',
                'phone' => '09170000020',
                'role' => 'customer',
            ],
            [
                'user_id' => 30,
                'first_name' => 'Staff',
                'last_name' => 'Member',
                'email' => 'staff@example.test',
                'phone' => '09170000030',
                'role' => 'staff',
            ],
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'Dog'],
            ['pet_id' => 102, 'user_id' => 10, 'pet_name' => 'Bruno', 'species' => 'Dog'],
            ['pet_id' => 202, 'user_id' => 20, 'pet_name' => 'Luna', 'species' => 'Cat'],
        ]);

        DB::table('bookings')->insert([
            [
                'booking_id' => 1,
                'booking_reference' => 'BOOK-OWNER',
                'user_id' => 10,
                'status' => 'checked_in',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'booking_id' => 2,
                'booking_reference' => 'BOOK-FOREIGN',
                'user_id' => 20,
                'status' => 'checked_in',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 11, 'booking_id' => 1, 'pet_id' => 101],
            ['booking_pet_id' => 12, 'booking_id' => 1, 'pet_id' => 102],
            ['booking_pet_id' => 21, 'booking_id' => 2, 'pet_id' => 202],
        ]);

        DB::table('clinic_appointments')->insert([
            'id' => 500,
            'pet_id' => 101,
            'appointment_reference' => 'CL-500',
        ]);
    }

    private function authenticateAs(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId), ['*']);
    }

    private function createConcern(array $overrides = []): GroomingMedicalConcern
    {
        return GroomingMedicalConcern::create(array_merge([
            'booking_id' => 1,
            'booking_pet_id' => 11,
            'pet_id' => 101,
            'reported_by_user_id' => 30,
            'reported_by_name' => 'Staff Member',
            'reported_at' => now(),
            'category' => 'skin',
            'severity' => GroomingMedicalConcern::SEVERITY_MODERATE,
            'internal_description' => 'Internal staff observation.',
            'customer_message' => 'Customer-safe concern message.',
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'status' => GroomingMedicalConcern::STATUS_OPEN,
            'acknowledgment_required' => false,
            'consent_required' => false,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
            'customer_notified_at' => null,
        ], $overrides));
    }

    private function expectedCustomerFields(): array
    {
        return [
            'public_id',
            'pet_name',
            'concern_date',
            'customer_message',
            'severity',
            'severity_label',
            'recommended_grooming_action',
            'recommended_grooming_action_label',
            'applied_grooming_action',
            'applied_grooming_action_label',
            'status',
            'status_label',
            'acknowledgment_required',
            'consent_required',
            'customer_response_status',
            'customer_response_status_label',
            'customer_notified_at',
            'clinic_appointment_reference',
            'customer_resolution_summary',
            'resolved_at',
            'customer_action_required',
            'required_customer_action',
            'required_customer_action_label',
        ];
    }

    private function forbiddenCustomerFields(): array
    {
        return [
            'id',
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'reported_by_user_id',
            'reported_by_name',
            'action_applied_by_user_id',
            'action_applied_by_name',
            'resolved_by_user_id',
            'resolved_by_name',
            'internal_description',
            'internal_resolution_notes',
            'report_token',
            'customer_visible_fields_editable',
            'staff_internal_fields_editable',
            'available_staff_actions',
            'created_at',
            'updated_at',
            'booking',
            'booking_pet',
            'pet',
            'reported_by',
            'resolved_by',
            'responses',
            'notifications',
            'payment',
            'inventory',
            'attachments',
        ];
    }
}
