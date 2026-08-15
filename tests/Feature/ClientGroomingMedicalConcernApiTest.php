<?php

namespace Tests\Feature;

use App\Http\Controllers\PetGroomingMedicalConcernController;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\User;
use App\Services\GroomingMedicalConcernResponseStatement;
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
                'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
                'acknowledgment_required' => true,
                'consent_required' => true,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'expected' => 'consent',
                'label' => 'Provide consent',
                'required' => true,
            ],
            [
                'message' => 'Acknowledgment case.',
                'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
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
                'status' => $case['status'] ?? GroomingMedicalConcern::STATUS_OPEN,
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

    public function test_routes_are_owner_scoped_and_central_helpers_power_read_and_response_ui(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $customerConcernRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with(
                $route->getActionName(),
                PetGroomingMedicalConcernController::class.'@',
            ),
        );

        $this->assertCount(4, $customerConcernRoutes);
        $customerConcernRoutes->each(function (RoutingRoute $route): void {
            $this->assertNotContains('PATCH', $route->methods());
            $this->assertNotContains('PUT', $route->methods());
            $this->assertNotContains('DELETE', $route->methods());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('role:admin,staff', $route->gatherMiddleware());
        });
        $this->assertCount(
            2,
            $customerConcernRoutes->filter(
                fn (RoutingRoute $route) => in_array('GET', $route->methods(), true),
            ),
        );
        $this->assertCount(
            2,
            $customerConcernRoutes->filter(
                fn (RoutingRoute $route) => in_array('POST', $route->methods(), true),
            ),
        );

        $apiLayer = file_get_contents(base_path('scripts/api.js'));
        foreach ([
            'getPetMedicalConcerns',
            'getPetMedicalConcern',
            'acknowledgePetMedicalConcern',
            'submitPetMedicalConcernConsent',
        ] as $helper) {
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
        $this->assertStringContainsString('acknowledgePetMedicalConcern', $clientComponent);
        $this->assertStringContainsString('submitPetMedicalConcernConsent', $clientComponent);
        $this->assertStringContainsString('data-pet-panel="grooming"', $clientPage);
        $this->assertStringContainsString('data-pet-panel="medical"', $clientPage);
        $this->assertStringContainsString('data-pet-panel="vaccinations"', $clientPage);
    }

    public function test_concern_approval_and_decline_use_the_customer_styled_confirmation_modal(): void
    {
        $clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));
        $modalStart = strpos($clientComponent, 'const renderConcernConsentConfirmation');
        $modalEnd = strpos($clientComponent, 'const attachConcernResponseActions', $modalStart ?: 0);

        $this->assertNotFalse($modalStart);
        $this->assertNotFalse($modalEnd);

        $modal = substr($clientComponent, $modalStart, $modalEnd - $modalStart);

        foreach ([
            'data-concern-consent-confirmation',
            'role="alertdialog"',
            'aria-modal="true"',
            'Confirm response',
            'Go back',
            'rounded-3xl',
            'bg-[#355c84]',
        ] as $contract) {
            $this->assertStringContainsString($contract, $modal);
        }

        $this->assertStringNotContainsString('uppercase', $modal);
        $this->assertStringContainsString('Confirm approval', $clientComponent);
        $this->assertStringContainsString('Confirm decline', $clientComponent);
        $this->assertStringContainsString('originatingButton?.focus()', $clientComponent);
        $this->assertStringNotContainsString('`${decisionLabel} the proposed action?', $clientComponent);
        $this->assertStringContainsString(
            'pet-details.js?v=pet-verified-alignment-20260809',
            $clientPage,
        );
    }

    public function test_response_routes_require_authentication_and_hide_foreign_or_unnotified_records(): void
    {
        $concern = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
        ]);

        $this->postJson(self::LIST_URI."/{$concern->public_id}/acknowledge")
            ->assertUnauthorized();
        $this->postJson(self::LIST_URI."/{$concern->public_id}/consent", [
            'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
            'signature_name' => 'Mochi Owner',
        ])->assertUnauthorized();

        $this->authenticateAs(20);
        $foreign = $this->postJson(
            self::LIST_URI."/{$concern->public_id}/acknowledge",
        )
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);
        $missing = $this->postJson(
            '/api/pets/999/medical-concerns/'.$concern->public_id.'/acknowledge',
        )
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);
        $this->assertSame($foreign->getContent(), $missing->getContent());

        $this->authenticateAs(30);
        $this->postJson(
            self::LIST_URI."/{$concern->public_id}/acknowledge",
        )
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $this->authenticateAs(10);
        $this->postJson(
            '/api/pets/102/medical-concerns/'.$concern->public_id.'/acknowledge',
        )
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Medical concern not found.',
            ]);

        $unnotified = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);
        $this->postJson(
            self::LIST_URI."/{$unnotified->public_id}/acknowledge",
        )
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Medical concern not found.',
            ]);

        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
    }

    public function test_owner_acknowledgment_stores_server_controlled_immutable_evidence_and_updates_concern(): void
    {
        $this->authenticateAs(10);
        $concern = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'consent_required' => false,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
        ]);
        $uri = self::LIST_URI."/{$concern->public_id}/acknowledge";

        $this->postJson($uri, [
            'statement_text' => 'Browser-controlled statement.',
            'statement_version' => 'browser-v99',
            'responded_by_name' => 'Impersonated owner',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'statement_text',
                'statement_version',
                'responded_by_name',
            ]);

        $beforeBooking = DB::table('bookings')->where('booking_id', 1)->first();
        $beforeBookingPet = DB::table('booking_pets')->where('booking_pet_id', 11)->first();

        $response = $this->postJson($uri)
            ->assertCreated()
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_OPEN)
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_ACKNOWLEDGED,
            )
            ->assertJsonPath(
                'response.response_kind',
                GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            )
            ->assertJsonPath(
                'response.decision',
                GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            );

        $stored = GroomingMedicalConcernResponse::query()->sole();
        $this->assertSame(
            GroomingMedicalConcernResponseStatement::ACKNOWLEDGMENT_VERSION,
            $stored->statement_version,
        );
        $this->assertStringContainsString('Mochi', $stored->statement_text);
        $this->assertStringContainsString(
            'Customer-safe concern message.',
            $stored->statement_text,
        );
        $this->assertStringContainsString('Pause grooming', $stored->statement_text);
        $this->assertStringNotContainsString('Internal staff observation.', $stored->statement_text);
        $this->assertSame('Mochi Owner', $stored->responded_by_name);
        $this->assertSame(10, $stored->responded_by_user_id);
        $this->assertNull($stored->signature_name);
        $this->assertSame(now()->toIso8601String(), $stored->responded_at->toIso8601String());
        $this->assertArrayNotHasKey('signature_name', $response->json('response'));
        $this->assertArrayNotHasKey('responded_by_user_id', $response->json('response'));

        $this->assertEquals(
            $beforeBooking,
            DB::table('bookings')->where('booking_id', 1)->first(),
        );
        $this->assertEquals(
            $beforeBookingPet,
            DB::table('booking_pets')->where('booking_pet_id', 11)->first(),
        );
        $this->assertDatabaseCount('clinic_appointments', 1);

        $this->getJson(self::LIST_URI."/{$concern->public_id}")
            ->assertOk()
            ->assertJsonPath('concern.required_customer_action', 'none')
            ->assertJsonPath(
                'concern.submitted_response.decision',
                GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            )
            ->assertJsonPath('concern.submitted_response.responded_by_name', 'Mochi Owner');

        $this->postJson($uri)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This customer response has already been submitted and cannot be changed.',
            );
        $this->assertDatabaseCount('grooming_medical_concern_responses', 1);
    }

    public function test_consent_approval_and_decline_require_signature_and_preserve_exact_decisions(): void
    {
        $this->authenticateAs(10);

        foreach ([
            GroomingMedicalConcernResponse::DECISION_APPROVED,
            GroomingMedicalConcernResponse::DECISION_DECLINED,
        ] as $index => $decision) {
            $concern = $this->createConcern([
                'category' => "consent-{$index}",
                'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
                'acknowledgment_required' => true,
                'consent_required' => true,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'customer_notified_at' => now(),
            ]);
            $uri = self::LIST_URI."/{$concern->public_id}/consent";

            $this->postJson($uri, ['decision' => $decision])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('signature_name');
            $this->postJson($uri, [
                'decision' => 'maybe',
                'signature_name' => 'Typed Evidence',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('decision');

            $apiResponse = $this->postJson($uri, [
                'decision' => $decision,
                'signature_name' => "Typed Evidence {$index}",
            ])
                ->assertCreated()
                ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_OPEN)
                ->assertJsonPath('concern.customer_response_status', $decision)
                ->assertJsonPath('response.decision', $decision);

            $stored = GroomingMedicalConcernResponse::query()
                ->where('concern_id', $concern->id)
                ->sole();
            $this->assertSame(
                GroomingMedicalConcernResponse::KIND_CONSENT,
                $stored->response_kind,
            );
            $this->assertSame(
                GroomingMedicalConcernResponseStatement::CONSENT_VERSION,
                $stored->statement_version,
            );
            $this->assertSame("Typed Evidence {$index}", $stored->signature_name);
            $this->assertStringContainsString('Selecting Approve', $stored->statement_text);
            $this->assertStringContainsString('Selecting Decline', $stored->statement_text);
            $this->assertStringNotContainsString(
                "Typed Evidence {$index}",
                $apiResponse->getContent(),
            );
            $this->assertDatabaseMissing('grooming_medical_concern_responses', [
                'concern_id' => $concern->id,
                'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            ]);

            $this->postJson($uri, [
                'decision' => $decision === GroomingMedicalConcernResponse::DECISION_APPROVED
                    ? GroomingMedicalConcernResponse::DECISION_DECLINED
                    : GroomingMedicalConcernResponse::DECISION_APPROVED,
                'signature_name' => 'Changed Decision',
            ])->assertConflict();
        }

        $this->assertDatabaseCount('grooming_medical_concern_responses', 2);
    }

    public function test_failed_response_insert_rolls_back_concern_status_and_response_evidence(): void
    {
        $this->authenticateAs(10);
        $concern = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
        ]);

        DB::statement(
            "CREATE TRIGGER fail_customer_concern_response
             BEFORE INSERT ON grooming_medical_concern_responses
             BEGIN
                 SELECT RAISE(FAIL, 'forced response failure');
             END",
        );

        try {
            $this->postJson(
                self::LIST_URI."/{$concern->public_id}/acknowledge",
            )->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_customer_concern_response');
        }

        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);
    }

    public function test_wrong_response_kind_terminal_and_no_requirement_states_are_rejected(): void
    {
        $this->authenticateAs(10);

        $consent = $this->createConcern([
            'category' => 'consent-only',
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'consent_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
        ]);
        $this->postJson(
            self::LIST_URI."/{$consent->public_id}/acknowledge",
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Consent is required for this medical concern; acknowledgment cannot be submitted instead.',
            );

        $acknowledgment = $this->createConcern([
            'category' => 'ack-only',
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now(),
        ]);
        $this->postJson(
            self::LIST_URI."/{$acknowledgment->public_id}/consent",
            [
                'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
                'signature_name' => 'Mochi Owner',
            ],
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Consent is not required for this medical concern.',
            );

        foreach ([
            GroomingMedicalConcern::STATUS_RESOLVED,
            GroomingMedicalConcern::STATUS_CANCELLED,
        ] as $index => $status) {
            $terminal = $this->createConcern([
                'category' => "terminal-{$index}",
                'status' => $status,
                'acknowledgment_required' => true,
                'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
                'customer_notified_at' => now(),
            ]);
            $this->postJson(
                self::LIST_URI."/{$terminal->public_id}/acknowledge",
            )->assertConflict();
        }

        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
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
            'response_statement',
            'response_statement_version',
            'typed_signature_required',
            'allowed_consent_decisions',
            'submitted_response',
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
