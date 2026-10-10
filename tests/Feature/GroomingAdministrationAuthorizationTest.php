<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WalkinController;
use App\Models\BookingPet;
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

class GroomingAdministrationAuthorizationTest extends TestCase
{
    private const FORBIDDEN_RESPONSE = [
        'success' => false,
        'message' => 'Forbidden. You do not have permission to access this resource.',
    ];

    private const GROOMING_ADMIN_ROUTES = [
        ['GET', 'api/admin/notifications'],
        ['PATCH', 'api/admin/notifications/read-all'],
        ['PATCH', 'api/admin/notifications/{id}/read'],
        ['GET', 'api/admin/bookings'],
        ['GET', 'api/admin/bookings/archived'],
        ['POST', 'api/admin/bookings/{id}/sedation-consent'],
        ['PATCH', 'api/admin/clinic/settings/groomers-on-duty'],
        ['POST', 'api/admin/bookings/{id}/check-in'],
        ['PATCH', 'api/admin/bookings/{id}/internal-staff-note'],
        ['PATCH', 'api/admin/bookings/{id}/pets/{bookingPetId}/grooming-visit-notes'],
        ['POST', 'api/admin/bookings/{id}/revert-check-in'],
        ['POST', 'api/admin/bookings/{id}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/revert-start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/cancel'],
        ['POST', 'api/admin/bookings/{id}/archive'],
        ['POST', 'api/admin/bookings/{id}/picked-up'],
        ['POST', 'api/admin/bookings/{id}/pay'],
        ['POST', 'api/admin/bookings/{id}/pay-now'],
        ['POST', 'api/admin/bookings/{id}/release'],
        ['GET', 'api/admin/transactions'],
        ['POST', 'api/admin/walk-in'],
        ['POST', 'api/admin/walk-in/capacity-preview'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00'));

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
            $table->string('password_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
        });

        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->time('clinic_open_time')->default('08:00:00');
            $table->time('clinic_close_time')->default('17:00:00');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_open_time')->default('08:00:00');
            $table->time('grooming_close_time')->default('17:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
        ]);

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('max_slots')->default(4);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('walkins', function (Blueprint $table) {
            $table->id();
            $table->string('fname');
            $table->string('lname');
            $table->string('mname')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->boolean('sedation_consent')->default(false);
            $table->boolean('terms_agreed')->default(false);
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('appointment_type')->default('grooming');
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('booking_type')->default('online');
            $table->string('status');
            $table->integer('queue_number')->nullable();
            $table->text('special_notes')->nullable();
            $table->text('internal_staff_note')->nullable();
            $table->boolean('sedation_consent')->default(false);
            $table->string('sedation_consent_source', 30)->nullable();
            $table->unsignedInteger('sedation_consent_recorded_by')->nullable();
            $table->dateTime('sedation_consent_recorded_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->boolean('paid')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->string('fur_type')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->string('grooming_preference')->nullable();
            $table->unsignedSmallInteger('grooming_estimate_min')->nullable();
            $table->unsignedSmallInteger('grooming_estimate_max')->nullable();
            $table->json('grooming_estimate_factors')->nullable();
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('registered_size')->nullable();
            $table->string('confirmed_size')->nullable();
            $table->date('pet_queue_date')->nullable();
            $table->unsignedInteger('pet_queue_number')->nullable();
            $table->text('special_instructions')->nullable();
            $table->unsignedInteger('groomer_id')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20)->default(
                BookingPet::GROOMING_STATE_NOT_STARTED,
            );
            $table->unique(['pet_queue_date', 'pet_queue_number']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price', 8, 2)->default(0);
            $table->decimal('price_small', 8, 2)->nullable();
            $table->decimal('price_medium', 8, 2)->nullable();
            $table->decimal('price_large', 8, 2)->nullable();
            $table->decimal('price_extra_large', 8, 2)->nullable();
            $table->json('starting_price_sizes')->nullable();
            $table->json('range_price_maximums')->nullable();
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->boolean('is_starting_price')->default(false);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->unsignedInteger('addon_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->unsignedInteger('processed_by')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('item_id');
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 10, 2);
        });

        (require database_path('migrations/2026_07_05_100002_create_pos_transactions_table.php'))->up();
        (require database_path('migrations/2026_07_05_100003_create_pos_transaction_items_table.php'))->up();
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type');
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        DB::table('services')->insert(['service_id' => 90, 'service_name' => 'Partial Grooming', 'slug' => 'partial_grooming',
            'base_price' => 250, 'price_small' => 250, 'price_medium' => 250, 'price_large' => 250, 'price_extra_large' => 250]);

        DB::table('services')->insert([
            'service_id' => 1,
            'service_name' => 'Basic Grooming',
            'slug' => 'basic-grooming',
            'base_price' => 500,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('pos_transaction_items');
        Schema::dropIfExists('pos_transactions');

        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('grooming_payment_products');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    #[DataProvider('unauthenticatedGroomingRoutes')]
    public function test_unauthenticated_visitors_cannot_access_grooming_administration(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->json($method, $uri, $payload)
            ->assertUnauthorized();
    }

    public static function unauthenticatedGroomingRoutes(): array
    {
        return [
            'queue listing' => ['GET', '/api/admin/bookings'],
            'edit internal staff note' => ['PATCH', '/api/admin/bookings/1/internal-staff-note', ['internal_staff_note' => 'private']],
            'edit grooming visit notes' => ['PATCH', '/api/admin/bookings/1/pets/1/grooming-visit-notes', ['grooming_visit_notes' => 'private']],
            'record sedation consent' => ['POST', '/api/admin/bookings/1/sedation-consent'],
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'start grooming' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish grooming' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
            'preview grooming walk-in capacity' => ['POST', '/api/admin/walk-in/capacity-preview'],
            'record grooming payment' => ['POST', '/api/admin/bookings/1/pay', [
                'final_price' => 500,
                'amount_paid' => 500,
            ]],
        ];
    }

    #[DataProvider('customerForbiddenGroomingRoutes')]
    public function test_customers_receive_the_standard_forbidden_response_for_every_grooming_admin_route(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->authenticateAs('customer');

        $this->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public static function customerForbiddenGroomingRoutes(): array
    {
        return [
            'staff notifications' => ['GET', '/api/admin/notifications'],
            'mark all staff notifications read' => ['PATCH', '/api/admin/notifications/read-all'],
            'mark staff notification read' => ['PATCH', '/api/admin/notifications/1/read'],
            'queue and incoming listing' => ['GET', '/api/admin/bookings'],
            'archived grooming listing' => ['GET', '/api/admin/bookings/archived'],
            'check in booking' => ['POST', '/api/admin/bookings/1/check-in'],
            'edit internal staff note' => ['PATCH', '/api/admin/bookings/1/internal-staff-note', ['internal_staff_note' => 'private']],
            'edit grooming visit notes' => ['PATCH', '/api/admin/bookings/1/pets/1/grooming-visit-notes', ['grooming_visit_notes' => 'private']],
            'record sedation consent' => ['POST', '/api/admin/bookings/1/sedation-consent'],
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'start whole booking' => ['POST', '/api/admin/bookings/1/start-grooming'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'start one pet' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish one pet' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'finish whole booking' => ['POST', '/api/admin/bookings/1/mark-done'],
            'staff cancellation' => ['POST', '/api/admin/bookings/1/cancel'],
            'archive booking' => ['POST', '/api/admin/bookings/1/archive'],
            'mark picked up' => ['POST', '/api/admin/bookings/1/picked-up'],
            'record final payment' => ['POST', '/api/admin/bookings/1/pay'],
            'record early payment' => ['POST', '/api/admin/bookings/1/pay-now'],
            'release paid booking' => ['POST', '/api/admin/bookings/1/release'],
            'grooming transaction listing' => ['GET', '/api/admin/transactions'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
            'preview grooming walk-in capacity' => ['POST', '/api/admin/walk-in/capacity-preview'],
        ];
    }

    #[DataProvider('customerSedationConsentCases')]
    public function test_customer_grooming_pre_registration_saves_optional_sedation_consent(
        bool $accepted,
        ?string $expectedSource,
        bool $expectsRecordedAt,
    ): void {
        $this->authenticateAs('customer');
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'sedation_consent' => $accepted,
            'pets' => [[
                'pet_name' => 'Mochi',
                'species' => 'cat',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $response->json('booking.booking_id'),
            'sedation_consent' => $accepted,
            'sedation_consent_source' => $expectedSource,
            'sedation_consent_recorded_by' => null,
        ]);
        $recordedAt = DB::table('bookings')->value('sedation_consent_recorded_at');
        if ($expectsRecordedAt) {
            $this->assertNotNull($recordedAt);
        } else {
            $this->assertNull($recordedAt);
        }
    }

    public static function customerSedationConsentCases(): array
    {
        return [
            'unchecked' => [false, null, false],
            'checked' => [true, 'customer_online', true],
        ];
    }

    public function test_pre_registration_uses_verified_size_for_existing_pet_and_price(): void
    {
        $this->authenticateAs('customer');
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 3,
            'user_id' => 3,
            'pet_name' => 'Rigby',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('services')->insert([
            'service_id' => 3,
            'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming',
            'base_price' => 0,
        ]);

        $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_id' => 3,
                'pet_name' => 'Rigby',
                'species' => 'dog',
                'weight' => 12,
                'size' => 'medium',
                'grooming_preference' => 'regular_trim',
                'services' => ['package' => 'regular_dog_grooming'],
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('pets', ['pet_id' => 3, 'size' => 'small', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', ['pet_id' => 3, 'registered_size' => 'small']);
        $this->assertDatabaseHas('booking_services', ['service_id' => 3, 'price_at_booking' => 550]);
    }

    public function test_customer_and_walk_in_range_prices_stay_unresolved_until_payment(): void
    {
        DB::table('services')->insert([
            'service_id' => 4,
            'service_name' => 'Nail Clipping',
            'slug' => 'nail_clipping',
            'base_price' => 75,
            'price_min' => 50,
            'price_max' => 100,
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->authenticateAs('customer');
        $customerBookingId = $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_name' => 'Mochi',
                'species' => 'cat',
                'weight' => 4,
                'services' => ['ala_carte' => ['nail_clipping']],
            ]],
        ])->assertCreated()->json('booking.booking_id');

        $this->authenticateAs('staff');
        $walkinBookingId = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'weight' => 8,
                'services' => [['service_slug' => 'nail_clipping']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');

        foreach ([$customerBookingId, $walkinBookingId] as $bookingId) {
            $this->assertDatabaseHas('booking_services', [
                'booking_id' => $bookingId,
                'price_at_booking' => 0,
                'price_min_at_booking' => 50,
                'price_max_at_booking' => 100,
            ]);
        }

        $listing = $this->getJson('/api/admin/bookings')->assertOk();
        $incoming = collect($listing->json('incomingList'))->firstWhere('id', $customerBookingId);
        $queued = collect($listing->json('queuedList'))->firstWhere('id', $walkinBookingId);
        $this->assertEquals(50, $incoming['services'][0]['priceMinAtBooking']);
        $this->assertEquals(100, $incoming['services'][0]['priceMaxAtBooking']);
        $this->assertEquals(0, $incoming['services'][0]['priceAtBooking']);
        $this->assertEquals(50, $queued['services'][0]['priceMinAtBooking']);
        $this->assertEquals(100, $queued['services'][0]['priceMaxAtBooking']);
        $this->assertEquals(0, $queued['services'][0]['priceAtBooking']);

        DB::table('services')->where('service_id', 4)->update(['price_min' => 60, 'price_max' => 70]);
        $walkinLineId = DB::table('booking_services')->where('booking_id', $walkinBookingId)->value('booking_service_id');
        $this->postJson("/api/admin/bookings/{$walkinBookingId}/pay-now", [
            'final_price' => 80,
            'amount_paid' => 80,
            'service_prices' => [['booking_service_id' => $walkinLineId, 'amount' => 80]],
        ])->assertOk();
        $this->assertDatabaseHas('booking_services', [
            'booking_service_id' => $walkinLineId,
            'price_at_booking' => 80,
            'price_min_at_booking' => 50,
            'price_max_at_booking' => 100,
        ]);
        $this->assertDatabaseHas('payments', ['booking_id' => $walkinBookingId, 'total_amount' => 80]);

        DB::table('bookings')->where('booking_id', $customerBookingId)->update(['status' => 'for_payment']);
        DB::table('booking_pets')->where('booking_id', $customerBookingId)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'grooming_end_time' => now(),
        ]);
        $customerLineId = DB::table('booking_services')->where('booking_id', $customerBookingId)->value('booking_service_id');
        $this->postJson("/api/admin/bookings/{$customerBookingId}/pay", [
            'final_price' => 101,
            'amount_paid' => 101,
            'service_prices' => [['booking_service_id' => $customerLineId, 'amount' => 101]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        $this->assertDatabaseHas('booking_services', ['booking_service_id' => $customerLineId, 'price_at_booking' => 0]);

        $this->postJson("/api/admin/bookings/{$customerBookingId}/pay", [
            'final_price' => 100,
            'amount_paid' => 100,
            'service_prices' => [['booking_service_id' => $customerLineId, 'amount' => 100]],
        ])->assertOk();
        $this->assertDatabaseHas('booking_services', [
            'booking_service_id' => $customerLineId,
            'price_at_booking' => 100,
            'price_min_at_booking' => 50,
            'price_max_at_booking' => 100,
        ]);

        $paidListing = $this->getJson('/api/admin/bookings')->assertOk();
        $this->assertEquals(80, collect($paidListing->json('queuedList'))->firstWhere('id', $walkinBookingId)['services'][0]['priceAtBooking']);
        $this->assertEquals(100, collect($paidListing->json('releasedList'))->firstWhere('id', $customerBookingId)['services'][0]['priceAtBooking']);
        $transactions = collect($this->getJson('/api/admin/transactions')->assertOk()->json('transactions'));
        $this->assertEquals(80, $transactions->firstWhere('bookingId', $walkinBookingId)['finalPrice']);
        $this->assertEquals(100, $transactions->firstWhere('bookingId', $customerBookingId)['finalPrice']);
    }

    #[DataProvider('livePricingModes')]
    public function test_updated_catalogue_propagates_to_new_customer_and_walk_in_snapshots(string $type, int $amount, ?int $maximum): void
    {
        DB::table('services')->insert(['service_id' => 4, 'service_name' => 'Nail Clipping',
            'slug' => 'nail_clipping', 'base_price' => 75, 'price_min' => 50, 'price_max' => 100]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $this->authenticateAs('admin');
        $this->patchJson('/api/admin/grooming/services/4/pricing', [
            'pricing_type' => $type, 'amount' => (string) $amount, 'maximum' => $maximum,
        ])->assertOk();
        $this->authenticateAs('customer');
        $customerId = $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Mochi', 'species' => 'cat', 'weight' => 4,
                'services' => ['ala_carte' => ['nail_clipping']]]],
        ])->assertCreated()->json('booking.booking_id');
        $this->authenticateAs('staff');
        $walkinId = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Bantay', 'species' => 'dog', 'weight' => 8,
                'services' => [['service_slug' => 'nail_clipping', 'price' => 1]]]],
            'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');
        $this->assertSame(
            (array) DB::table('booking_pets')->where('booking_id', $customerId)->first(['grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max']),
            (array) DB::table('booking_pets')->where('booking_id', $walkinId)->first(['grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max']),
        );
        foreach ([$customerId, $walkinId] as $id) {
            $this->assertDatabaseHas('booking_services', ['booking_id' => $id,
                'price_at_booking' => $type === 'range' ? 0 : $amount,
                'price_min_at_booking' => $amount,
                'price_max_at_booking' => $type === 'fixed' ? $amount : $maximum]);
        }
        $before = DB::table('booking_services')->get()->toArray();
        $this->authenticateAs('admin');
        $this->patchJson('/api/admin/grooming/services/4/pricing', ['pricing_type' => 'fixed', 'amount' => '300'])->assertOk();
        $this->assertEquals($before, DB::table('booking_services')->get()->toArray());
        $line = DB::table('booking_services')->where('booking_id', $walkinId)->first();
        $invalid = $type === 'fixed' ? $amount + 1 : ($type === 'range' ? $maximum + 1 : $amount + 201);
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $invalid, 'amount_paid' => $invalid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $invalid]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $amount, 'amount_paid' => $amount,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $amount]],
        ])->assertOk();
    }

    public static function livePricingModes(): array
    {
        return [['fixed', 200, 200], ['range', 65, 125], ['starting_at', 175, null]];
    }

    #[DataProvider('updatedPackageModes')]
    public function test_updated_package_prices_propagate_and_payment_enforces_size_rules(string $size, int $weight, string $type, int $amount, ?int $threshold, ?int $maximum = null): void
    {
        DB::table('services')->insert(['service_id' => 4, 'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming', 'base_price' => 650]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $this->authenticateAs('admin');
        $sizes = [];
        foreach (['small' => 600, 'medium' => 700, 'large' => 950, 'extra_large' => 1150] as $key => $value) {
            $sizes[$key] = ['pricing_type' => in_array($key, ['large', 'extra_large']) ? 'starting_at' : 'fixed', 'amount' => (string) $value];
        }
        $sizes[$size]['pricing_type'] = $type;
        if ($type === 'range') $sizes[$size]['maximum'] = (string) $maximum;
        $this->patchJson('/api/admin/grooming/services/4/pricing', ['sizes' => $sizes])->assertOk();
        $this->authenticateAs('customer');
        $customerId = $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Rigby', 'species' => 'dog', 'weight' => $weight,
                'grooming_preference' => 'regular_trim',
                'services' => ['package' => 'regular_dog_grooming']]],
        ])->assertCreated()->json('booking.booking_id');
        $this->authenticateAs('staff');
        $walkinId = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Bantay', 'species' => 'dog', 'weight' => $weight,
                'grooming_preference' => 'regular_trim',
                    'services' => [['service_slug' => 'regular_dog_grooming', 'price' => 1]]]],
            'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');
        $this->assertSame(
            (array) DB::table('booking_pets')->where('booking_id', $customerId)->first(['grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max']),
            (array) DB::table('booking_pets')->where('booking_id', $walkinId)->first(['grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max']),
        );
        foreach ([$customerId, $walkinId] as $id) {
            $this->assertDatabaseHas('booking_services', ['booking_id' => $id, 'price_at_booking' => $type === 'range' ? 0 : $amount,
                'price_min_at_booking' => $amount, 'price_max_at_booking' => $type === 'fixed' ? $amount : $maximum]);
        }
        if ($type === 'range') {
            $before = DB::table('booking_services')->get()->toArray();
            $this->authenticateAs('admin');
            $sizes[$size]['maximum'] = (string) ($maximum + 100);
            $this->patchJson('/api/admin/grooming/services/4/pricing', ['sizes' => $sizes])->assertOk();
            $this->assertEquals($before, DB::table('booking_services')->get()->toArray());
        }
        $line = DB::table('booking_services')->where('booking_id', $walkinId)->first();
        $invalid = $type === 'fixed' ? $amount + 1 : ($type === 'range' ? $maximum + 1 : $amount + 501);
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $invalid, 'amount_paid' => $invalid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $invalid]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        if ($type === 'range') {
            $below = $amount - 1;
            $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $below, 'amount_paid' => $below,
                'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $below]],
            ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        }
        $valid = $maximum ?? $threshold ?? $amount;
        $response = $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $valid, 'amount_paid' => $valid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $valid]],
        ])->assertOk();
        if ($threshold !== null) $response->assertJsonCount(1, 'service_price_warnings');
        else $response->assertJsonCount(0, 'service_price_warnings');
    }

    public static function updatedPackageModes(): array
    {
        return [['small', 8, 'fixed', 600, null], ['small', 8, 'starting_at', 600, null],
            ['large', 30, 'starting_at', 950, 1150],
            ['small', 8, 'range', 600, null, 680],
            ['medium', 15, 'range', 700, null, 850],
            ['large', 30, 'range', 950, null, 1100],
            ['extra_large', 60, 'range', 1150, null, 1800]];
    }

    public function test_staff_can_record_in_person_sedation_consent_before_grooming(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'SEDATION-CONSENT-1',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'booking_type' => 'online',
            'status' => 'waiting_to_arrive',
            'sedation_consent' => false,
        ]);
        $this->authenticateAs('staff');

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('incomingList.0.sedationConsent', false)
            ->assertJsonPath('incomingList.0.canRecordSedationConsent', true);

        $this->postJson('/api/admin/bookings/1/sedation-consent', [
            'customer_understood_and_agreed' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_understood_and_agreed');

        $this->postJson('/api/admin/bookings/1/sedation-consent', [
            'customer_understood_and_agreed' => true,
        ])->assertOk()
            ->assertJsonPath('sedation_consent.accepted', true)
            ->assertJsonPath('sedation_consent.source', 'staff_in_person');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'sedation_consent' => true,
            'sedation_consent_source' => 'staff_in_person',
            'sedation_consent_recorded_by' => 2,
        ]);
        $this->assertNotNull(DB::table('bookings')->value('sedation_consent_recorded_at'));

        DB::table('bookings')->insert([
            'booking_id' => 2,
            'booking_reference' => 'SEDATION-CONSENT-STARTED',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'booking_type' => 'online',
            'status' => 'in_progress',
            'sedation_consent' => false,
        ]);

        $this->postJson('/api/admin/bookings/2/sedation-consent', [
            'customer_understood_and_agreed' => true,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Sedation consent can only be recorded before grooming starts.',
            );
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 2,
            'sedation_consent' => false,
            'sedation_consent_source' => null,
        ]);
    }

    public function test_pre_registration_internal_note_updates_one_booking_and_locks_at_payment(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'STAFF-NOTE-1',
            'booking_date' => now()->toDateString(),
            'status' => 'waiting_to_arrive',
            'special_notes' => 'Customer supplied note',
        ]);
        $this->authenticateAs('staff');

        foreach (['waiting_to_arrive', 'checked_in', 'in_progress'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/internal-staff-note', [
                'internal_staff_note' => 'Call owner before extra services',
            ])->assertOk()->assertJsonPath('internal_staff_note', 'Call owner before extra services');
        }

        foreach (['for_payment', 'for_pickup', 'archived'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/internal-staff-note', [
                'internal_staff_note' => 'Changed too late',
            ])->assertUnprocessable();
        }

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'special_notes' => 'Customer supplied note',
            'internal_staff_note' => 'Call owner before extra services',
        ]);
    }

    public function test_walk_in_visit_notes_update_only_the_selected_pet_and_lock_at_payment(): void
    {
        DB::table('walkins')->insert([
            'id' => 1, 'fname' => 'Walk', 'lname' => 'In', 'phone' => '09170000000',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'WALK-NOTE-1',
            'walkin_id' => 1,
            'booking_date' => now()->toDateString(),
            'status' => 'checked_in',
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'special_instructions' => 'Short trim'],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'special_instructions' => 'Avoid perfume'],
        ]);
        $this->authenticateAs('staff');

        $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
            'grooming_visit_notes' => 'Nervous around dryers',
        ])->assertOk()->assertJsonPath('grooming_visit_notes', 'Nervous around dryers');

        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'in_progress']);
        $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
            'grooming_visit_notes' => 'Short trim, nervous around dryers',
        ])->assertOk();

        foreach (['for_payment', 'for_pickup', 'archived'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
                'grooming_visit_notes' => 'Changed too late',
            ])->assertUnprocessable();
        }

        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'special_instructions' => 'Short trim, nervous around dryers']);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 2, 'special_instructions' => 'Avoid perfume']);
    }

    public function test_revert_check_in_restores_admin_and_customer_state_and_allows_check_in_again(): void
    {
        DB::table('users')->insert([
            'user_id' => 100,
            'first_name' => 'Queue',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 100,
            'booking_reference' => 'REVERT-CHECK-IN-100',
            'user_id' => 100,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'waiting_to_arrive',
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 100, 'user_id' => 100, 'pet_name' => 'Alpha', 'species' => 'dog', 'size' => 'small'],
            ['pet_id' => 101, 'user_id' => 100, 'pet_name' => 'Beta', 'species' => 'cat', 'size' => 'small'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 100, 'booking_id' => 100, 'pet_id' => 100, 'grooming_estimate_min' => 45, 'grooming_estimate_max' => 60],
            ['booking_pet_id' => 101, 'booking_id' => 100, 'pet_id' => 101, 'grooming_estimate_min' => 45, 'grooming_estimate_max' => 60],
        ]);

        $this->authenticateAs('admin');

        $this->postJson('/api/admin/bookings/100/check-in')
            ->assertOk()
            ->assertJsonPath('queue_number', 1);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 100,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 100)->value('dropped_off_at'));
        $this->assertSame(
            [1, 2],
            DB::table('booking_pets')
                ->where('booking_id', 100)
                ->orderBy('booking_pet_id')
                ->pluck('pet_queue_number')
                ->map(fn ($number) => (int) $number)
                ->all(),
        );

        $this->postJson('/api/admin/bookings/100/revert-check-in')
            ->assertOk()
            ->assertJsonPath('status', 'waiting_to_arrive');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 100,
            'status' => 'waiting_to_arrive',
            'queue_number' => null,
            'dropped_off_at' => null,
        ]);
        $this->assertSame(0, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_date')->count());
        $this->assertSame(0, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_number')->count());

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('incomingList.0.id', 100)
            ->assertJsonCount(0, 'queuedList')
            ->assertJsonPath('capacity.current', 0);

        Sanctum::actingAs(User::query()->findOrFail(100), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.status', 'waiting_to_arrive')
            ->assertJsonPath('bookings.0.dropped_off_at', null)
            ->assertJsonPath('bookings.0.pets.0.grooming_status', 'waiting_to_arrive');
        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.active', 0)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.waiting', 1);

        $this->authenticateAs('admin');

        $this->postJson('/api/admin/bookings/100/check-in')
            ->assertOk()
            ->assertJsonPath('queue_number', 1);
        $this->assertSame(2, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_number')->count());
    }

    public function test_check_in_confirms_size_independently_of_weight_and_notifies_owner(): void
    {
        DB::table('users')->insert([
            'user_id' => 120,
            'first_name' => 'Rigby',
            'last_name' => 'Owner',
            'role' => 'customer',
        ]);
        DB::table('pets')->insert([
            'pet_id' => 120,
            'user_id' => 120,
            'pet_name' => 'Rigby',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'medium',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 120,
            'booking_reference' => 'SIZE-120',
            'user_id' => 120,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 120,
            'booking_id' => 120,
            'pet_id' => 120,
            'registered_size' => 'medium',
            'grooming_estimate_min' => 45,
            'grooming_estimate_max' => 60,
        ]);

        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/120/check-in', [
            'pet_sizes' => [['booking_pet_id' => 120, 'size' => 'small']],
            'internal_staff_note' => 'Call owner before extra services',
        ])->assertOk();

        $this->assertDatabaseHas('bookings', ['booking_id' => 120, 'internal_staff_note' => 'Call owner before extra services']);
        $this->assertDatabaseHas('pets', ['pet_id' => 120, 'size' => 'small', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 120,
            'registered_size' => 'medium',
            'confirmed_size' => 'small',
        ]);
        $this->assertSame(['size'], json_decode(DB::table('pets')->where('pet_id', 120)->value('clinic_verified_fields'), true));
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 120,
            'pet_id' => 120,
            'type' => 'pet_information_updated',
        ]);
    }

    public function test_revert_grooming_start_restores_queue_and_removes_customer_notification(): void
    {
        DB::table('users')->insert([
            'user_id' => 110,
            'first_name' => 'Started',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 110,
            'booking_reference' => 'REVERT-START-110',
            'user_id' => 110,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'checked_in',
            'queue_number' => 1,
            'dropped_off_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 110,
            'user_id' => 110,
            'pet_name' => 'Gamma',
            'species' => 'dog',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 110,
            'booking_id' => 110,
            'pet_id' => 110,
            'pet_queue_date' => now()->toDateString(),
            'pet_queue_number' => 1,
        ]);

        $this->authenticateAs('staff');

        $this->postJson('/api/admin/bookings/110/start-grooming')
            ->assertOk();
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 110,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 110,
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'booking_id' => 110,
            'type' => 'grooming_started',
        ]);

        $this->postJson('/api/admin/bookings/110/revert-start-grooming')
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 110,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => null,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 110,
            'pet_queue_number' => 1,
            'grooming_start_time' => null,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
        $this->assertDatabaseMissing('customer_notifications', [
            'booking_id' => 110,
            'type' => 'grooming_started',
        ]);

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('queuedList.0.id', 110)
            ->assertJsonCount(0, 'inProgressList');

        Sanctum::actingAs(User::query()->findOrFail(110), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.status', 'checked_in')
            ->assertJsonPath('bookings.0.grooming_started_at', null)
            ->assertJsonPath('bookings.0.pets.0.grooming_status', 'checked_in');
        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.queued', 1)
            ->assertJsonPath('queue.in_progress', 0);

        $this->authenticateAs('staff');

        $this->postJson('/api/admin/bookings/110/start-grooming')
            ->assertOk();
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_revert_endpoints_refuse_to_erase_later_grooming_activity(): void
    {
        $this->authenticateAs('admin');
        $this->seedMultiPetBooking();

        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')->assertOk();

        $this->postJson('/api/admin/bookings/1/revert-check-in')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Check-in cannot be reverted after grooming activity has started.');

        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson('/api/admin/bookings/1/revert-start-grooming')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Grooming cannot be reverted after a later grooming action has occurred.');

        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 1,
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);
    }

    public function test_revert_buttons_use_persisted_api_handlers(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        $dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));

        $this->assertStringContainsString('async function adminRevertCheckIn(bookingId)', $api);
        $this->assertStringContainsString('async function adminRevertStartGrooming(bookingId)', $api);
        $this->assertStringContainsString('await API.adminRevertCheckIn(booking.id)', $dashboard);
        $this->assertStringContainsString('await API.adminRevertStartGrooming(booking.id)', $dashboard);
        $this->assertStringNotContainsString('revertQueuedBookingFrontendOnly', $dashboard);
        $this->assertStringNotContainsString('revertInProgressBookingFrontendOnly', $dashboard);
        $this->assertStringNotContainsString('booking-reverted-ui-only', $dashboard);
    }

    public function test_archive_payload_has_constant_query_count_and_keeps_the_page_contract(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        DB::table('unregistered_customers')->insert([
            'id' => 30,
            'first_name' => 'Taylor',
            'last_name' => 'Reyes',
            'phone' => 'd123456789012345678',
            'is_archived' => true,
            'account_deleted_at' => now(),
        ]);
        DB::table('walkins')->insert([
            'id' => 20,
            'fname' => 'Taylor',
            'lname' => 'Reyes',
            'phone' => '09981234567',
            'unregistered_customer_id' => 30,
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '9:00 AM - 10:00 AM',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $bookings = [];
        $pets = [];
        $bookingPets = [];
        $bookingServices = [];
        $payments = [];

        for ($id = 1; $id <= 12; $id++) {
            $isWalkin = $id % 2 === 0;
            $bookings[] = [
                'booking_id' => $id,
                'booking_reference' => "ARCHIVE-{$id}",
                'user_id' => $isWalkin ? null : 10,
                'walkin_id' => $isWalkin ? 20 : null,
                'window_id' => 1,
                'booking_date' => '2026-07-24',
                'number_of_pets' => 1,
                'status' => 'archived',
                'queue_number' => $id,
                'special_notes' => 'Use sensitive shampoo.',
                'internal_staff_note' => $isWalkin ? null : 'Call before extra services',
                'total_amount' => 500,
                'paid' => true,
                'archived_at' => Carbon::parse('2026-07-24 12:00:00')->addMinutes($id),
                'dropped_off_at' => '2026-07-24 08:45:00',
                'grooming_started_at' => '2026-07-24 09:00:00',
                'grooming_finished_at' => '2026-07-24 10:00:00',
            ];
            $pets[] = [
                'pet_id' => $id,
                'user_id' => $isWalkin ? null : 10,
                'pet_name' => "Pet {$id}",
                'species' => 'dog',
                'breed' => 'Poodle',
                'weight' => 8.5,
                'size' => 'small',
                'fur_type' => 'curly',
                'medical_conditions' => 'None',
            ];
            $bookingPets[] = [
                'booking_pet_id' => $id,
                'booking_id' => $id,
                'pet_id' => $id,
                'pet_queue_date' => '2026-07-24',
                'pet_queue_number' => $id,
                'special_instructions' => 'Trim nails.',
                'grooming_start_time' => '2026-07-24 09:00:00',
                'grooming_end_time' => '2026-07-24 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ];
            $bookingServices[] = [
                'booking_service_id' => $id,
                'booking_id' => $id,
                'booking_pet_id' => $id,
                'service_id' => 1,
                'price_at_booking' => 500,
            ];
            $payments[] = [
                'payment_id' => $id,
                'booking_id' => $id,
                'total_amount' => 500,
                'amount_tendered' => 500,
                'change_amount' => 0,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'paid_at' => '2026-07-24 10:05:00',
            ];
        }

        DB::table('bookings')->insert($bookings);
        DB::table('pets')->insert($pets);
        DB::table('booking_pets')->insert($bookingPets);
        DB::table('booking_services')->insert($bookingServices);
        DB::table('payments')->insert($payments);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->getJson('/api/admin/bookings/archived');

        $this->assertLessThanOrEqual(
            13,
            $queryCount,
            'Archive queries must not grow with the number of bookings.',
        );
        $response->assertJsonStructure([
            'success',
            'total',
            'archived' => [
                '*' => [
                    'id',
                    'queueNumber',
                    'ownerName',
                    'contactNumber',
                    'ownerAccountDeleted',
                    'owner_account_deleted',
                    'petName',
                    'petType',
                    'breed',
                    'petSize',
                    'size',
                    'serviceLabel',
                    'appointmentDate',
                    'appointmentTime',
                    'dropOffTime',
                    'startedAt',
                    'completedAt',
                    'clientNotified',
                    'paid',
                    'status',
                    'bookingReference',
                    'specialNotes',
                    'numberOfPets',
                    'paidAmount',
                    'paid_amount',
                    'paymentReady',
                    'payment_ready',
                    'paymentBlockedReason',
                    'payment_blocked_reason',
                    'finalPaymentTotal',
                    'final_payment_total',
                    'paymentSummary' => [
                        'payment_ready',
                        'payment_blocked_reason',
                        'final_booking_total',
                        'pets',
                    ],
                    'payment_summary' => [
                        'payment_ready',
                        'payment_blocked_reason',
                        'final_booking_total',
                        'pets',
                    ],
                    'payment' => [
                        'id',
                        'finalPrice',
                        'final_price',
                        'amountPaid',
                        'amount_paid',
                        'paymentMethod',
                        'payment_method',
                        'paidAt',
                        'paid_at',
                    ],
                    'pets' => [
                        '*' => [
                            'id',
                            'bookingPetId',
                            'booking_pet_id',
                            'petId',
                            'pet_id',
                            'pet_name',
                            'name',
                            'petType',
                            'pet_type',
                            'petSize',
                            'pet_size',
                            'petName',
                            'species',
                            'breed',
                            'size',
                            'furType',
                            'weight',
                            'medicalConditions',
                            'specialInstructions',
                            'petQueueNumber',
                            'groomingStartedAt',
                            'isGroomingStarted',
                            'groomingStartedAtIso',
                            'groomingFinishedAt',
                            'groomingFinishedAtIso',
                            'isGroomingFinished',
                            'groomingState',
                            'grooming_state',
                            'groomingStateLabel',
                            'grooming_state_label',
                        ],
                    ],
                    'services' => [
                        '*' => [
                            'id',
                            'bookingServiceId',
                            'booking_service_id',
                            'bookingPetId',
                            'booking_pet_id',
                            'petId',
                            'pet_id',
                            'petName',
                            'pet_name',
                            'serviceId',
                            'service_id',
                            'slug',
                            'serviceSlug',
                            'service_slug',
                            'serviceName',
                            'service_name',
                            'description',
                            'name',
                            'priceAtBooking',
                            'price_at_booking',
                            'durationMinutes',
                            'paidPrice',
                            'paid_price',
                            'paidPriceSource',
                            'paid_price_source',
                            'paymentTotal',
                            'payment_total',
                        ],
                    ],
                    'archivedAt',
                ],
            ],
        ]);
        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 12)
            ->assertJsonPath('archived.0.id', 12)
            ->assertJsonPath('archived.0.ownerName', 'Taylor Reyes')
            ->assertJsonPath('archived.0.contactNumber', '—')
            ->assertJsonPath('archived.0.ownerAccountDeleted', true)
            ->assertJsonPath('archived.1.ownerName', 'Jamie Santos')
            ->assertJsonPath('archived.1.contactNumber', '09171234567')
            ->assertJsonPath('archived.1.ownerAccountDeleted', false)
            ->assertJsonPath('archived.0.bookingReference', 'ARCHIVE-12')
            ->assertJsonPath('archived.0.petName', 'Pet 12')
            ->assertJsonPath('archived.0.petType', 'Dog')
            ->assertJsonPath('archived.0.breed', 'Poodle')
            ->assertJsonPath('archived.0.appointmentDate', '2026-07-24')
            ->assertJsonPath('archived.0.appointmentTime', '9:00 AM - 10:00 AM')
            ->assertJsonPath('archived.0.dropOffTime', '8:45 AM')
            ->assertJsonPath('archived.0.startedAt', '9:00 AM')
            ->assertJsonPath('archived.0.completedAt', '10:00 AM')
            ->assertJsonPath('archived.0.archivedAt', 'Jul 24, 2026 12:12 PM')
            ->assertJsonPath('archived.0.serviceLabel', 'Basic Grooming')
            ->assertJsonPath('archived.0.numberOfPets', 1)
            ->assertJsonPath('archived.0.specialNotes', 'Use sensitive shampoo.')
            ->assertJsonPath('archived.0.internalStaffNote', null)
            ->assertJsonPath('archived.1.internalStaffNote', 'Call before extra services')
            ->assertJsonPath('archived.0.paidAmount', 500)
            ->assertJsonPath('archived.0.paid_amount', 500)
            ->assertJsonPath('archived.0.paid', true)
            ->assertJsonPath('archived.0.status', 'archived')
            ->assertJsonPath('archived.0.paymentReady', true)
            ->assertJsonPath('archived.0.payment_ready', true)
            ->assertJsonPath('archived.0.finalPaymentTotal', '500.00')
            ->assertJsonPath('archived.0.final_payment_total', '500.00')
            ->assertJsonPath('archived.0.paymentSummary.payment_ready', true)
            ->assertJsonPath('archived.0.paymentSummary.final_booking_total', '500.00')
            ->assertJsonPath('archived.0.payment.finalPrice', 500)
            ->assertJsonPath('archived.0.payment.final_price', 500)
            ->assertJsonPath('archived.0.payment.id', 12)
            ->assertJsonPath('archived.0.payment.amountPaid', 500)
            ->assertJsonPath('archived.0.payment.paymentMethod', 'cash')
            ->assertJsonPath('archived.0.pets.0.petName', 'Pet 12')
            ->assertJsonPath('archived.0.pets.0.bookingPetId', 12)
            ->assertJsonPath('archived.0.pets.0.petId', 12)
            ->assertJsonPath('archived.0.pets.0.species', 'Dog')
            ->assertJsonPath('archived.0.pets.0.breed', 'Poodle')
            ->assertJsonPath('archived.0.pets.0.size', 'small')
            ->assertJsonPath('archived.0.pets.0.furType', 'curly')
            ->assertJsonPath('archived.0.pets.0.weight', '8.5 kg')
            ->assertJsonPath('archived.0.pets.0.medicalConditions', 'None')
            ->assertJsonPath('archived.0.pets.0.specialInstructions', 'Trim nails.')
            ->assertJsonPath('archived.0.pets.0.groomingState', BookingPet::GROOMING_STATE_FINISHED)
            ->assertJsonPath('archived.0.pets.0.groomingStateLabel', 'Finished')
            ->assertJsonPath('archived.0.services.0.name', 'Basic Grooming')
            ->assertJsonPath('archived.0.services.0.bookingServiceId', 12)
            ->assertJsonPath('archived.0.services.0.serviceId', 1)
            ->assertJsonPath('archived.0.services.0.serviceSlug', 'basic-grooming')
            ->assertJsonPath('archived.0.services.0.paidPrice', 500)
            ->assertJsonPath('archived.0.services.0.paid_price', 500)
            ->assertJsonPath('archived.0.services.0.paidPriceSource', 'payment_total')
            ->assertJsonPath('archived.0.services.0.paymentTotal', 500);

    }

    public function test_grooming_history_keeps_repeat_visits_and_transaction_snapshots_independent(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('users')->insert(['user_id' => 10, 'first_name' => 'Jamie', 'last_name' => 'Santos', 'role' => 'customer']);
        DB::table('walkins')->insert(['id' => 20, 'fname' => 'Jamie', 'lname' => 'Santos', 'phone' => '09171234567']);
        DB::table('bookings')->where('booking_id', 1)->update([
            'user_id' => 10, 'status' => 'archived', 'paid' => true, 'total_amount' => 725,
            'archived_at' => '2026-07-24 10:30:00', 'grooming_started_at' => '2026-07-24 09:00:00',
            'grooming_finished_at' => '2026-07-24 10:00:00', 'special_notes' => 'First visit customer note',
            'internal_staff_note' => 'First visit staff note',
        ]);
        DB::table('booking_pets')->where('booking_id', 1)->update([
            'registered_size' => 'small', 'confirmed_size' => 'medium',
            'grooming_start_time' => '2026-07-24 09:00:00', 'grooming_end_time' => '2026-07-24 10:00:00',
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED, 'special_instructions' => 'First visit instructions',
        ]);
        DB::table('booking_services')->insert([
            'booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 1,
            'service_id' => 1, 'price_at_booking' => 75,
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 2, 'booking_reference' => 'WI-REPEAT-VISIT', 'walkin_id' => 20,
            'booking_date' => '2026-07-25', 'status' => 'archived', 'paid' => true, 'total_amount' => 400,
            'archived_at' => '2026-07-26 01:05:00', 'grooming_started_at' => '2026-07-25 23:30:00',
            'grooming_finished_at' => '2026-07-26 01:00:00', 'special_notes' => 'Repeat walk-in note',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 3, 'booking_id' => 2, 'pet_id' => 1, 'confirmed_size' => 'large',
            'grooming_start_time' => '2026-07-25 23:30:00', 'grooming_end_time' => '2026-07-26 01:00:00',
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED, 'special_instructions' => 'Repeat visit instructions',
        ]);
        DB::table('booking_services')->insert([
            'booking_service_id' => 4, 'booking_id' => 2, 'booking_pet_id' => 3,
            'service_id' => 1, 'price_at_booking' => 400,
        ]);
        DB::table('payments')->insert([
            ['payment_id' => 1, 'booking_id' => 1, 'total_amount' => 725, 'amount_tendered' => 1000,
                'change_amount' => 275, 'payment_method' => 'cash', 'payment_status' => 'paid',
                'paid_at' => '2026-07-24 10:15:00', 'notes' => 'First payment note'],
            ['payment_id' => 2, 'booking_id' => 2, 'total_amount' => 400, 'amount_tendered' => 400,
                'change_amount' => 0, 'payment_method' => 'gcash', 'payment_status' => 'paid',
                'paid_at' => '2026-07-26 01:02:00', 'notes' => 'Recorded transfer reference 123456'],
        ]);
        DB::table('grooming_payment_products')->insert([
            'payment_id' => 1, 'item_id' => 1, 'item_name' => 'Historical shampoo',
            'quantity' => 2, 'price_at_sale' => 75, 'subtotal' => 150,
        ]);
        // A changed live catalogue and pet profile cannot replace session snapshots.
        DB::table('services')->where('service_id', 1)->update(['base_price' => 9999, 'price_medium' => 9999, 'price_large' => 9999]);
        DB::table('pets')->where('pet_id', 1)->update(['size' => 'extra_large']);

        $response = $this->getJson('/api/admin/bookings/archived')->assertOk()->assertJsonPath('total', 2);
        $records = collect($response->json('archived'))->keyBy('id');
        $first = $records[1];
        $repeat = $records[2];
        $this->assertSame('MULTI-PET-SECURITY', $first['bookingReference']);
        $this->assertSame('Pre-Register', $first['bookingType']);
        $this->assertCount(2, $first['pets']);
        $this->assertSame(['medium', 'medium'], array_column($first['pets'], 'confirmedSize'));
        $this->assertSame([1, 2, 1], array_column($first['services'], 'bookingPetId'));
        $this->assertEquals([250, 250, 75], array_column($first['services'], 'paidPrice'));
        $this->assertSame('First visit instructions', $first['pets'][0]['specialInstructions']);
        $this->assertSame('First visit customer note', $first['specialNotes']);
        $this->assertSame('First visit staff note', $first['internalStaffNote']);
        $this->assertEquals(725, $first['payment']['finalPrice']);
        $this->assertSame('cash', $first['payment']['paymentMethod']);
        $this->assertSame('First payment note', $first['payment']['notes']);
        $this->assertEquals(275, $first['payment']['changeAmount']);
        $this->assertEquals(75, $first['productAddons'][0]['priceAtSale']);
        $this->assertEquals(150, $first['productAddons'][0]['subtotal']);
        $this->assertSame('WI-REPEAT-VISIT', $repeat['bookingReference']);
        $this->assertSame('Walk-In', $repeat['bookingType']);
        $this->assertCount(1, $repeat['pets']);
        $this->assertSame(1, $repeat['pets'][0]['petId']);
        $this->assertSame(3, $repeat['pets'][0]['bookingPetId']);
        $this->assertSame('large', $repeat['pets'][0]['confirmedSize']);
        $this->assertSame('Repeat visit instructions', $repeat['pets'][0]['specialInstructions']);
        $this->assertSame('Repeat walk-in note', $repeat['specialNotes']);
        $this->assertSame('gcash', $repeat['payment']['paymentMethod']);
        $this->assertSame('Recorded transfer reference 123456', $repeat['payment']['notes']);
        $this->assertEquals(400, $repeat['payment']['finalPrice']);
        $this->assertEquals(400, $repeat['services'][0]['paidPrice']);
        $this->assertSame([], $repeat['productAddons']);
        $this->assertStringStartsWith('2026-07-25T23:30:00', $repeat['startedAtIso']);
        $this->assertStringStartsWith('2026-07-26T01:00:00', $repeat['completedAtIso']);
        $this->assertSame('2026-07-26 01:00:00', Carbon::parse($repeat['pets'][0]['groomingFinishedAtIso'])->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('booking_services', ['booking_service_id' => 1, 'price_at_booking' => 250]);
    }

    #[DataProvider('authorizedGroomingRoles')]
    public function test_staff_and_admin_can_complete_the_existing_core_grooming_workflow(
        string $role,
    ): void {
        $this->authenticateAs($role);
        $this->seedMultiPetBooking();

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('queuedList.0.id', 1)
            ->assertJsonPath('capacity.used', 2);

        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')
            ->assertOk()
            ->assertJsonPath('all_pets_started', false);
        $this->postJson('/api/admin/bookings/1/pets/2/start-grooming')
            ->assertOk()
            ->assertJsonPath('all_pets_started', true)
            ->assertJsonPath('booking_status', 'in_progress');

        $this->postJson('/api/admin/bookings/1/pets/1/mark-done')
            ->assertOk()
            ->assertJsonPath('all_pets_finished', false);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);
        $this->postJson('/api/admin/bookings/1/pets/2/mark-done')
            ->assertOk()
            ->assertJsonPath('all_pets_finished', true)
            ->assertJsonPath('booking_status', 'for_payment');

        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);

        $this->postJson('/api/admin/bookings/1/pay', [
            'final_price' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'service_prices' => [
                ['booking_service_id' => 1, 'amount' => 250],
                ['booking_service_id' => 2, 'amount' => 250],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('final_price', '500.00');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'status' => 'released',
            'paid' => true,
        ]);
        $this->assertDatabaseHas('payments', [
            'booking_id' => 1,
            'payment_status' => 'paid',
        ]);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);

        DB::table('bookings')->insert([
            'booking_id' => 2,
            'booking_reference' => "RELEASE-{$role}",
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'for_payment',
            'queue_number' => 2,
            'paid' => true,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 3,
            'pet_name' => 'Ready',
            'species' => 'dog',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 3,
            'booking_id' => 2,
            'pet_id' => 3,
            'pet_queue_date' => now()->toDateString(),
            'pet_queue_number' => 3,
            'grooming_start_time' => now()->subHour(),
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson('/api/admin/bookings/2/release')
            ->assertOk();
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 2,
            'status' => 'released',
        ]);

        $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'weight' => 8,
                'services' => [[
                    'service_slug' => 'partial_grooming',
                ]],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'checked_in')
            ->assertJsonPath('pets.0.pet_name', 'Bantay');

        DB::table('users')->insert([
            'user_id' => 100,
            'first_name' => 'Gerald',
            'last_name' => 'Senining',
            'role' => 'customer',
        ]);
        DB::table('bookings')->where('booking_id', 1)->update(['user_id' => 100]);

        $this->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'payment_confirmed')
            ->assertJsonPath(
                'notifications.0.message',
                'Payment received from Gerald Senining for booking MULTI-PET-SECURITY.',
            )
            ->assertJsonFragment(['type' => 'payment_due']);
        $this->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonPath('transactions.0.bookingId', 1);

        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 4);
        $this->postJson('/api/admin/bookings/1/picked-up')->assertOk();
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 2);
    }

    public static function authorizedGroomingRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    public function test_existing_registered_and_unregistered_owners_keep_pet_ownership_on_walk_in(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Active',
            'last_name' => 'Owner',
            'email' => 'active-owner@example.test',
            'phone' => '09170000050',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 50,
            'user_id' => 50,
            'pet_name' => 'Buddy',
            'species' => 'Dog',
            'weight' => 12,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('services')->insert([
            'service_id' => 2,
            'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming',
            'base_price' => 0,
        ]);

        $registeredResponse = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Ignored',
            'lname' => 'Name',
            'phone' => '09171111111',
            'owner_record_type' => 'registered',
            'customer_user_id' => 50,
            'pets' => [
                [
                    'pet_id' => 50,
                    'pet_name' => 'Buddy',
                    'species' => 'Dog',
                    'weight' => 12,
                    'size' => 'medium',
                    'grooming_preference' => 'regular_trim',
                    'services' => [['service_slug' => 'regular_dog_grooming']],
                ],
                [
                    'pet_name' => 'Luna',
                    'species' => 'Dog',
                    'weight' => 8,
                    'services' => [['service_slug' => 'partial_grooming']],
                ],
            ],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('owner.name', 'Active Owner');

        $registeredBookingId = $registeredResponse->json('booking_id');
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $registeredBookingId,
            'user_id' => 50,
            'booking_type' => 'walk_in',
        ]);
        $this->assertDatabaseHas('pets', [
            'user_id' => 50,
            'pet_name' => 'Luna',
        ]);
        $this->assertDatabaseHas('pets', ['pet_id' => 50, 'size' => 'small', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', ['pet_id' => 50, 'confirmed_size' => 'small']);
        $this->assertDatabaseHas('booking_services', ['service_id' => 2, 'price_at_booking' => 550]);

        DB::table('booking_services')->delete();
        DB::table('booking_pets')->delete();
        DB::table('bookings')->delete();

        $unregisteredId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Unregistered',
            'last_name' => 'Owner',
            'phone' => '09170000060',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/admin/walk-in', [
            'fname' => 'Ignored',
            'lname' => 'Again',
            'phone' => '09172222222',
            'owner_record_type' => 'unregistered',
            'unregistered_customer_id' => $unregisteredId,
            'pets' => [[
                'pet_name' => 'Milo',
                'species' => 'Cat',
                'weight' => 4,
                'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()
            ->assertJsonPath('owner.name', 'Unregistered Owner');

        $this->assertDatabaseHas('pets', [
            'unregistered_customer_id' => $unregisteredId,
            'pet_name' => 'Milo',
        ]);
        $this->assertDatabaseHas('walkins', [
            'unregistered_customer_id' => $unregisteredId,
        ]);
    }

    public function test_successful_new_owner_pickup_creates_an_unregistered_customer_and_assigns_the_pet(): void
    {
        $this->authenticateAs('staff');

        $response = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'mname' => 'A',
            'email' => 'maria.walkin@example.test',
            'phone' => '09171234567',
            'owner_record_type' => 'new',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'size' => 'small',
                'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated();

        $bookingId = $response->json('booking_id');
        $petId = $response->json('pets.0.pet_id');
        $walkinId = DB::table('bookings')->where('booking_id', $bookingId)->value('walkin_id');
        $bookingPetId = DB::table('booking_pets')->where('booking_id', $bookingId)->value('booking_pet_id');

        $this->assertDatabaseCount('unregistered_customers', 0);
        $this->assertDatabaseHas('pets', [
            'pet_id' => $petId,
            'user_id' => null,
            'unregistered_customer_id' => null,
        ]);

        DB::table('bookings')->where('booking_id', $bookingId)->update([
            'status' => 'released',
            'paid' => true,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', $bookingPetId)->update([
            'grooming_start_time' => now()->subHour(),
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson("/api/admin/bookings/{$bookingId}/picked-up")
            ->assertOk()
            ->assertJsonPath('success', true);

        $customerId = DB::table('unregistered_customers')
            ->where('phone', '09171234567')
            ->value('id');

        $this->assertNotNull($customerId);
        $this->assertDatabaseHas('unregistered_customers', [
            'id' => $customerId,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'middle_name' => 'A',
            'created_by_user_id' => 2,
        ]);
        $this->assertDatabaseHas('walkins', [
            'id' => $walkinId,
            'unregistered_customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('pets', [
            'pet_id' => $petId,
            'unregistered_customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $bookingId,
            'status' => 'archived',
        ]);
    }

    public function test_walk_in_submission_enforces_duplicate_phone_and_similar_name_confirmation(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
            'phone' => '09170000050',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $payload = [
            'fname' => 'Another',
            'lname' => 'Owner',
            'phone' => '09170000050',
            'owner_record_type' => 'new',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'size' => 'small',
                'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ];

        $this->postJson('/api/admin/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $similarPayload = [
            ...$payload,
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09170000051',
        ];

        $this->postJson('/api/admin/walk-in', $similarPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'similar_customer_name');

        $this->postJson('/api/admin/walk-in', [
            ...$similarPayload,
            'confirm_similar_name' => true,
        ])->assertCreated();

        $this->assertDatabaseCount('unregistered_customers', 0);
    }

    public function test_admin_cancellation_notifies_the_customer_with_the_optional_or_default_reason(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Rudito',
            'last_name' => 'Gracia',
            'email' => 'rudito@example.test',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 50,
                'booking_reference' => 'CANCEL-CUSTOM-50',
                'user_id' => 50,
                'booking_date' => now()->addDay()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'incoming',
            ],
            [
                'booking_id' => 51,
                'booking_reference' => 'CANCEL-DEFAULT-51',
                'user_id' => 50,
                'booking_date' => now()->addDays(2)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'incoming',
            ],
        ]);

        $customMessage = 'Your grooming pre-registration CANCEL-CUSTOM-50 has been cancelled by the clinic. Reason: Owner requested a different date.';
        $defaultMessage = 'Your grooming pre-registration CANCEL-DEFAULT-51 has been cancelled by the clinic.';

        $this->postJson('/api/admin/bookings/50/cancel', [
            'cancellation_reason' => '  Owner requested a different date.  ',
        ])
            ->assertOk()
            ->assertJsonPath('customer_notified', true);

        $this->postJson('/api/admin/bookings/51/cancel', [
            'cancellation_reason' => null,
        ])
            ->assertOk()
            ->assertJsonPath('customer_notified', true);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 50,
            'status' => 'cancelled',
            'cancellation_reason' => 'Owner requested a different date.',
            'cancel_count' => 1,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 51,
            'status' => 'cancelled',
            'cancellation_reason' => 'Cancelled by clinic staff.',
            'cancel_count' => 1,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 50,
            'booking_id' => 50,
            'type' => 'booking_cancelled',
            'message' => $customMessage,
            'is_read' => false,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 50,
            'booking_id' => 51,
            'type' => 'booking_cancelled',
            'message' => $defaultMessage,
            'is_read' => false,
        ]);
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'booking_id' => 50,
            'type' => 'cancelled',
            'is_read' => false,
        ]);
        $this->assertDatabaseHas('notifications', [
            'booking_id' => 51,
            'type' => 'cancelled',
            'is_read' => false,
        ]);
        $this->assertDatabaseCount('notifications', 2);

        $this->postJson('/api/admin/bookings/50/cancel')
            ->assertUnprocessable();
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseCount('notifications', 2);

        Sanctum::actingAs(User::query()->findOrFail(50), ['*']);

        $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonFragment([
                'type' => 'booking_cancelled',
                'message' => $customMessage,
                'display_message' => $customMessage,
            ])
            ->assertJsonFragment([
                'type' => 'booking_cancelled',
                'message' => $defaultMessage,
                'display_message' => $defaultMessage,
            ]);
    }

    public function test_invalid_staff_cancellation_reason_does_not_cancel_or_notify(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 60,
            'first_name' => 'Customer',
            'last_name' => 'User',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 60,
            'booking_reference' => 'CANCEL-VALIDATION-60',
            'user_id' => 60,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'incoming',
        ]);

        $this->postJson('/api/admin/bookings/60/cancel', [
            'cancellation_reason' => str_repeat('a', 501),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cancellation_reason');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 60,
            'status' => 'incoming',
            'cancel_count' => 0,
        ]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_customer_notification_failure_rolls_back_admin_cancellation(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 70,
            'first_name' => 'Customer',
            'last_name' => 'Rollback',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 70,
            'booking_reference' => 'CANCEL-ROLLBACK-70',
            'user_id' => 70,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'incoming',
        ]);

        DB::statement(
            "CREATE TRIGGER reject_booking_cancellation_notification
             BEFORE INSERT ON customer_notifications
             WHEN NEW.type = 'booking_cancelled'
             BEGIN
                 SELECT RAISE(ABORT, 'simulated notification failure');
             END",
        );

        try {
            $this->postJson('/api/admin/bookings/70/cancel', [
                'cancellation_reason' => 'Clinic is unavailable.',
            ])->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_booking_cancellation_notification');
        }

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 70,
            'status' => 'incoming',
            'cancellation_reason' => null,
            'cancel_count' => 0,
        ]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_cancelled_pre_registrations_never_appear_in_admin_or_customer_grooming_history(): void
    {
        DB::table('users')->insert([
            'user_id' => 80,
            'first_name' => 'History',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 80,
                'booking_reference' => 'LEGACY-CANCELLED-ARCHIVE',
                'user_id' => 80,
                'booking_date' => now()->subDays(3)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'archived',
                'cancellation_reason' => 'Cancelled by clinic staff.',
                'cancel_count' => 1,
                'archived_at' => now()->subDays(2),
            ],
            [
                'booking_id' => 81,
                'booking_reference' => 'COMPLETED-GROOMING-HISTORY',
                'user_id' => 80,
                'booking_date' => now()->subDays(2)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'archived',
                'cancellation_reason' => null,
                'cancel_count' => 0,
                'archived_at' => now()->subDay(),
            ],
            [
                'booking_id' => 82,
                'booking_reference' => 'CURRENTLY-CANCELLED',
                'user_id' => 80,
                'booking_date' => now()->addDay()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'cancelled',
                'cancellation_reason' => 'Customer is unavailable.',
                'cancel_count' => 1,
                'archived_at' => null,
            ],
        ]);

        $this->authenticateAs('admin');

        $this->getJson('/api/admin/bookings/archived')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('archived.0.bookingReference', 'COMPLETED-GROOMING-HISTORY')
            ->assertJsonMissing(['bookingReference' => 'LEGACY-CANCELLED-ARCHIVE'])
            ->assertJsonMissing(['bookingReference' => 'CURRENTLY-CANCELLED']);

        Sanctum::actingAs(User::query()->findOrFail(80), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonPath('history_total', 1)
            ->assertJsonPath('history.0.booking_reference', 'COMPLETED-GROOMING-HISTORY')
            ->assertJsonMissing(['booking_reference' => 'LEGACY-CANCELLED-ARCHIVE'])
            ->assertJsonMissing(['booking_reference' => 'CURRENTLY-CANCELLED']);
    }

    public function test_customer_capacity_matches_visible_queued_and_in_progress_pets(): void
    {
        DB::table('users')->insert([
            'user_id' => 90,
            'first_name' => 'Capacity',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 90,
                'booking_reference' => 'CAPACITY-CANCELLED',
                'user_id' => 90,
                'booking_date' => now()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'cancelled',
                'cancellation_reason' => 'Cancelled by clinic staff.',
                'cancel_count' => 1,
                'dropped_off_at' => null,
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 90,
                'booking_id' => 90,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
        ]);

        Sanctum::actingAs(User::query()->findOrFail(90), ['*']);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('capacity.used', 0)
            ->assertJsonPath('capacity.remaining', 20)
            ->assertJsonPath('capacity.percent', 0)
            ->assertJsonPath('queue.active', 0)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.in_progress', 0);

        DB::table('bookings')->insert([
            'booking_id' => 92,
            'booking_reference' => 'CAPACITY-ACTIVE',
            'user_id' => 90,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'in_progress',
            'dropped_off_at' => now(),
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 92,
                'booking_id' => 92,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
                'grooming_start_time' => null,
            ],
            [
                'booking_pet_id' => 93,
                'booking_id' => 92,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
                'grooming_start_time' => now(),
            ],
        ]);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('capacity.used', 2)
            ->assertJsonPath('capacity.remaining', 18)
            ->assertJsonPath('capacity.percent', 10)
            ->assertJsonPath('queue.active', 2)
            ->assertJsonPath('queue.queued', 1)
            ->assertJsonPath('queue.in_progress', 1);
    }

    public function test_staff_and_admin_can_change_the_groomer_capacity_setting(): void
    {
        $this->authenticateAs('staff');

        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', [
            'groomers_on_duty' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('groomers_on_duty', 3);

        $this->authenticateAs('admin');

        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', [
            'groomers_on_duty' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('groomers_on_duty', 4);
    }

    public function test_every_grooming_administration_route_has_authentication_and_role_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach (self::GROOMING_ADMIN_ROUTES as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected grooming route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        $controllerClasses = [
            AdminBookingController::class,
            NotificationController::class,
            PaymentController::class,
            WalkinController::class,
        ];
        $controllerRoutes = $routes->filter(
            fn (RoutingRoute $route) => $route->getActionName()
                === ClinicSettingController::class.'@updateGroomersOnDuty'
                || collect($controllerClasses)->contains(
                    fn (string $controller) => str_starts_with(
                        $route->getActionName(),
                        $controller.'@',
                    ),
                ),
        );

        $this->assertCount(count(self::GROOMING_ADMIN_ROUTES), $controllerRoutes);
        $controllerRoutes->each(function (RoutingRoute $route): void {
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        });
    }

    public function test_customer_grooming_and_pet_routes_do_not_inherit_staff_role_middleware(): void
    {
        $customerRoutes = [
            ['POST', 'api/booking/store'],
            ['GET', 'api/booking/history'],
            ['GET', 'api/booking/grooming-capacity'],
            ['GET', 'api/booking/{id}'],
            ['POST', 'api/booking/cancel'],
            ['POST', 'api/booking/reschedule'],
            ['GET', 'api/pets'],
            ['GET', 'api/pets/{id}'],
            ['GET', 'api/pets/{petId}/medical-records'],
            ['GET', 'api/pets/{petId}/vaccinations'],
            ['GET', 'api/customer/notifications'],
            ['PATCH', 'api/customer/notifications/read-all'],
            ['PATCH', 'api/customer/notifications/{id}/read'],
        ];
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($customerRoutes as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected customer route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('role:admin,staff', $route->gatherMiddleware());
            $this->assertNotContains('role:admin', $route->gatherMiddleware());
        }
    }

    public function test_grooming_pre_registrations_share_preferred_times_without_reserving_capacity_or_queue(): void
    {
        DB::table('time_windows')->insert([
            'window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'is_active' => true,
        ]);
        foreach (range(1, 6) as $customer) {
            DB::table('users')->insert(['user_id' => 100 + $customer, 'role' => 'customer']);
            Sanctum::actingAs(User::findOrFail(100 + $customer), ['*']);
            $this->postJson('/api/booking/store', [
                'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 4,
                'pets' => array_fill(0, 4, ['pet_name' => 'Test pet', 'species' => 'cat']),
            ])->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
        }
        $this->assertSame(24, (int) DB::table('bookings')->sum('number_of_pets'));
        $this->assertSame(0, DB::table('bookings')->whereNotNull('queue_number')->count());
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        $this->getJson('/api/timeslots?date='.now()->toDateString())->assertOk()
            ->assertJsonMissingPath('windows.0.is_full')->assertJsonMissingPath('windows.0.recommended');
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 0);

        DB::table('bookings')->insert(['booking_id' => 999, 'booking_reference' => 'FULL-ON-SITE',
            'booking_date' => now()->toDateString(), 'number_of_pets' => 20, 'status' => 'released']);
        foreach (range(1, 20) as $pet) {
            DB::table('booking_pets')->insert(['booking_id' => 999, 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        }
        DB::table('users')->insert(['user_id' => 107, 'role' => 'customer']);
        Sanctum::actingAs(User::findOrFail(107), ['*']);
        $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Another pet', 'species' => 'cat']],
        ])->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 20);
        $this->getJson('/api/timeslots?date='.now()->toDateString())->assertOk()
            ->assertJsonMissingPath('windows.0.is_full')->assertJsonMissingPath('windows.0.recommended');
    }

    public function test_grooming_multi_pet_intake_respects_on_site_capacity_ignores_clinic_and_preferred_time(): void
    {
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('status');
        });
        try {
            foreach (range(1, 19) as $pet) {
                DB::table('clinic_appointments')->insert(['status' => 'in_consultation']);
            }
            $this->seedMultiPetBooking();
            DB::table('booking_pets')->update(['grooming_estimate_min' => 10, 'grooming_estimate_max' => 15]);
            DB::table('bookings')->insert(['booking_id' => 99, 'booking_reference' => 'ON-SITE-19',
                'booking_date' => now()->toDateString(), 'number_of_pets' => 19, 'status' => 'released']);
            foreach (range(1, 19) as $pet) {
                DB::table('booking_pets')->insert(['booking_id' => 99, 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
            }
            DB::table('bookings')->where('booking_id', 1)->update(['status' => 'waiting_to_arrive', 'queue_number' => null, 'dropped_off_at' => null]);
            DB::table('booking_pets')->update(['pet_queue_number' => null, 'pet_queue_date' => null]);
            $this->authenticateAs('staff');
            $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
                ['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small'],
            ]])->assertUnprocessable();
            $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'waiting_to_arrive', 'queue_number' => null]);
            DB::table('bookings')->where('booking_id', 99)->update(['status' => 'archived']);
            Carbon::setTestNow(now()->setTime(16, 30));
            $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
                ['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small'],
            ]])->assertOk()->assertJsonPath('queue_number', 1);
            $this->getJson('/api/admin/bookings')->assertOk()
                ->assertJsonPath('capacity.current', 2)->assertJsonPath('summary.waitingNow', 2);
        } finally {
            Schema::dropIfExists('clinic_appointments');
        }
    }

    #[DataProvider('confirmedEstimateSizes')]
    public function test_grooming_visit_estimates_follow_confirmed_size_and_reach_both_dashboards(string $size, int $minutes, string $formatted): void
    {
        $this->authenticateAs('admin');
        $this->seedMultiPetBooking();
        DB::table('services')->insert([
            ['service_id' => 91, 'service_name' => 'Regular Dog Grooming', 'slug' => 'regular_dog_grooming', 'base_price' => 650],
            ['service_id' => 92, 'service_name' => 'Full Grooming', 'slug' => 'cat_full_grooming', 'base_price' => 500],
        ]);
        DB::table('booking_services')->where('booking_pet_id', 1)->update(['service_id' => 91]);
        DB::table('booking_services')->where('booking_pet_id', 2)->update(['service_id' => 92]);
        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'waiting_to_arrive', 'user_id' => 3]);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'registered_size' => 'medium', 'grooming_preference' => 'regular_trim',
            'grooming_estimate_min' => 90, 'grooming_estimate_max' => 105,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 2)->update([
            'registered_size' => 'small', 'grooming_estimate_min' => 90, 'grooming_estimate_max' => 90,
        ]);
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => $size], ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => $minutes, 'grooming_estimate_max' => $minutes]);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 2, 'grooming_estimate_min' => 90, 'grooming_estimate_max' => 90]);
        $this->getJson('/api/admin/bookings')->assertOk()
            ->assertJsonPath('queuedList.0.pets.0.groomingEstimate.formatted', $formatted)
            ->assertJsonPath('queuedList.0.pets.1.groomingEstimate.formatted', '1 hr 30 min');
        $this->authenticateAs('customer');
        $this->getJson('/api/booking/history')->assertOk()
            ->assertJsonPath('bookings.0.pets.0.grooming_estimate.formatted', $formatted)
            ->assertJsonPath('bookings.0.pets.0.grooming_estimate.minMinutes', $minutes);
        $this->authenticateAs('admin');
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming', ['estimate_factors' => ['thick_coat', 'extra_handling']])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1,
            'grooming_start_time' => now()->toDateTimeString(), 'grooming_estimate_min' => 120, 'grooming_estimate_max' => 240]);
        $this->authenticateAs('customer');
        $this->getJson('/api/booking/history')->assertOk()
            ->assertJsonPath('bookings.0.pets.0.grooming_estimate.formatted', '2–4 hrs')
            ->assertJsonPath('bookings.0.pets.0.grooming_estimate.preferenceLabel', 'Regular Trim')
            ->assertJsonPath('bookings.0.pets.1.grooming_estimate.minMinutes', 90);
    }

    public static function confirmedEstimateSizes(): array
    {
        return [['large', 120, '2 hrs'], ['small', 90, '1 hr 30 min']];
    }

    #[DataProvider('packageFacialEstimates')]
    public function test_customer_and_walkin_store_the_same_package_and_separately_charged_facial_estimate(string $package, ?string $preference, string $species, int $weight, array $range, int $packagePrice): void
    {
        DB::table('services')->where('slug', $package)->delete();
        DB::table('services')->insert([
            ['service_id' => 91, 'service_name' => 'Grooming', 'slug' => $package, 'base_price' => $packagePrice],
            ['service_id' => 92, 'service_name' => 'Facial Trimming', 'slug' => 'facial_trimming', 'base_price' => 150],
        ]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $pet = ['pet_name' => 'Coco', 'species' => $species, 'weight' => $weight, 'grooming_preference' => $preference];
        $this->authenticateAs('customer');
        $customer = $this->postJson('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [array_merge($pet, ['services' => ['package' => $package, 'ala_carte' => ['facial_trimming']]])],
        ])->assertCreated()->assertJsonPath('booking.pets.0.grooming_estimate.minMinutes', $range[0])
            ->assertJsonPath('booking.pets.0.grooming_estimate.maxMinutes', $range[1]);
        $this->authenticateAs('staff');
        $walkin = $this->postJson('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [array_merge($pet, ['services' => [
                ['service_slug' => $package, 'price' => 1], ['service_slug' => 'facial_trimming', 'price' => 1],
            ]])], 'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated();
        foreach ([$customer->json('booking.booking_id'), $walkin->json('booking_id')] as $id) {
            $this->assertDatabaseHas('booking_pets', ['booking_id' => $id, 'grooming_preference' => $preference,
                'grooming_estimate_min' => $range[0], 'grooming_estimate_max' => $range[1]]);
            $this->assertDatabaseHas('booking_services', ['booking_id' => $id, 'service_id' => 92, 'price_at_booking' => 150]);
            $this->assertEquals($packagePrice + 150, DB::table('booking_services')->where('booking_id', $id)->sum('price_at_booking'));
        }
    }

    public static function packageFacialEstimates(): array
    {
        return [
            ['partial_grooming', null, 'dog', 8, [60, 90], 400],
            ['regular_dog_grooming', 'summer_cut', 'dog', 8, [90, 105], 550],
            ['deluxe_dog_grooming', 'puppy_cut', 'dog', 8, [105, 135], 650],
            ['bath_and_go', null, 'dog', 8, [60, 75], 450],
            ['cat_full_grooming', null, 'cat', 4, [105, 120], 500],
        ];
    }

    public function test_server_derives_estimates_and_rejects_customer_staff_factors_or_missing_preference(): void
    {
        $this->authenticateAs('customer');
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        DB::table('services')->insert(['service_id' => 91, 'service_name' => 'Regular Dog Grooming', 'slug' => 'regular_dog_grooming', 'base_price' => 650]);
        $pet = ['pet_name' => 'Coco', 'species' => 'dog', 'weight' => 8, 'services' => ['package' => 'regular_dog_grooming']];
        $payload = ['booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1, 'pets' => [$pet]];
        $this->postJson('/api/booking/store', $payload)->assertUnprocessable()->assertJsonValidationErrors('grooming_preference');
        $payload['pets'][0]['grooming_preference'] = 'regular_trim';
        $payload['pets'][0]['estimate_factors'] = ['extra_handling'];
        $this->postJson('/api/booking/store', $payload)->assertUnprocessable()->assertJsonValidationErrors('pets.0.estimate_factors');
        unset($payload['pets'][0]['estimate_factors']);
        $payload['pets'][0]['grooming_estimate_min'] = 1;
        $payload['pets'][0]['grooming_estimate_max'] = 1;
        $this->postJson('/api/booking/store', $payload)->assertCreated()
            ->assertJsonPath('booking.pets.0.grooming_estimate.minMinutes', 90)
            ->assertJsonPath('booking.pets.0.grooming_estimate.maxMinutes', 90);
        $this->assertDatabaseHas('booking_pets', ['grooming_preference' => 'regular_trim', 'grooming_estimate_min' => 90, 'grooming_estimate_max' => 90]);
    }

    public function test_check_in_refreshes_rules_and_admitted_and_completed_snapshots_survive_later_rule_changes(): void
    {
        $this->authenticateAs('admin');
        $this->seedMultiPetBooking();
        DB::table('booking_services')->update(['service_id' => 90]);
        DB::table('booking_pets')->update(['registered_size' => 'small', 'grooming_estimate_min' => 30, 'grooming_estimate_max' => 45]);
        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'waiting_to_arrive']);
        config(['grooming_estimates.packages.partial_grooming.sizes.small' => [35, 50]]);
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => 35, 'grooming_estimate_max' => 50]);
        config(['grooming_estimates.packages.partial_grooming.sizes.small' => [40, 55]]);
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming', ['estimate_factors' => []])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => 35, 'grooming_estimate_max' => 50]);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $booking = \App\Models\Booking::with(['bookingPets.pet', 'bookingServices.service'])->find(1);
        app(\App\Services\GroomingTimeEstimate::class)->recalculate($booking, $booking->bookingPets->first(), ['extra_handling']);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => 35, 'grooming_estimate_max' => 50]);
    }

    public function test_estimate_migration_only_backfills_unfinished_active_visits(): void
    {
        $this->seedMultiPetBooking();
        DB::table('booking_services')->update(['service_id' => 90]);
        DB::table('services')->where('service_id', 90)->update(['duration_minutes' => 9999]);
        DB::table('booking_pets')->update(['registered_size' => 'small']);
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        Schema::table('booking_pets', fn (Blueprint $table) => $table->dropColumn([
            'grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max', 'grooming_estimate_factors',
        ]));
        $migration = require base_path('database/migrations/2026_10_09_000001_add_grooming_estimates_to_booking_pets.php');
        $migration->up();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => 45, 'grooming_estimate_max' => 60]);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 2, 'grooming_estimate_min' => null, 'grooming_estimate_max' => null]);
    }

    #[DataProvider('groomingArrivalWindowCases')]
    public function test_grooming_arrival_window_availability_and_submission(string $time, string $date, ?string $restriction, bool $accepted): void
    {
        Carbon::setTestNow("2026-10-09 {$time}");
        $this->authenticateAs('customer');
        DB::table('clinic_settings')->where('id', 1)->update([
            'grooming_open_time' => '18:00:00', 'grooming_close_time' => '22:00:00',
            'grooming_prereg_cutoff_time' => $restriction === 'cutoff' ? '19:00:00' : '22:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1, 'start_time' => '19:00:00', 'end_time' => '20:00:00', 'window_label' => '7:00 PM - 8:00 PM',
        ]);
        if (in_array($restriction, ['blocked_date', 'stop_today'], true)) {
            DB::table('clinic_closures')->insert(['type' => $restriction, 'start_date' => $date, 'end_date' => $date]);
        }

        $availability = $this->getJson('/api/timeslots?date='.$date)->assertOk();
        if ($restriction === 'cutoff') {
            $availability->assertJsonPath('cutoff_passed', true)->assertJsonCount(0, 'windows');
        } else {
            $availability->assertJsonPath('windows.0.is_past', $date === '2026-10-09' && $time >= '20:00:00')
                ->assertJsonPath('windows.0.is_closed', $restriction !== null);
        }
        $response = $this->postJson('/api/booking/store', [
            'booking_date' => $date, 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Mochi', 'species' => 'cat']],
        ]);
        if ($accepted) {
            $response->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
            $this->assertDatabaseHas('bookings', ['window_id' => 1, 'queue_number' => null, 'dropped_off_at' => null]);
        } else {
            $response->assertUnprocessable();
            $this->assertDatabaseCount('bookings', 0);
            if ($restriction === null) {
                $response->assertJsonPath('code', 'arrival_window_ended')
                    ->assertJsonPath('message', 'That arrival window just ended. Please choose the next available time.')
                    ->assertJsonValidationErrors('window_id');
            } else {
                $this->assertNotSame('arrival_window_ended', $response->json('code'));
            }
        }
    }

    public static function groomingArrivalWindowCases(): array
    {
        return [
            'before start' => ['18:59:00', '2026-10-09', null, true],
            'at start' => ['19:00:00', '2026-10-09', null, true],
            'inside window' => ['19:05:00', '2026-10-09', null, true],
            'before end' => ['19:59:00', '2026-10-09', null, true],
            'at end' => ['20:00:00', '2026-10-09', null, false],
            'cutoff during window' => ['19:05:00', '2026-10-09', 'cutoff', false],
            'blocked date' => ['19:05:00', '2026-10-09', 'blocked_date', false],
            'stopped today' => ['19:05:00', '2026-10-09', 'stop_today', false],
            'future date after cutoff' => ['23:00:00', '2026-10-10', null, true],
        ];
    }

    public function test_workload_check_in_rejects_atomically_and_more_groomers_allow_admission(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('booking_services')->update(['service_id' => 90]);
        DB::table('booking_pets')->update(['registered_size' => 'small', 'grooming_estimate_min' => 45,
            'grooming_estimate_max' => 60, 'pet_queue_number' => null, 'pet_queue_date' => null]);
        DB::table('bookings')->update(['status' => 'waiting_to_arrive', 'queue_number' => null, 'dropped_off_at' => null]);
        DB::table('clinic_settings')->update(['groomers_on_duty' => 1]);
        Carbon::setTestNow(now()->setTime(15, 30));
        $sizes = ['pet_sizes' => [['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small']]];
        $this->postJson('/api/admin/bookings/1/check-in', $sizes)->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient grooming time remaining')
            ->assertJsonPath('detail', "Based on the current grooming queue, this service is projected to finish after today's grooming hours.");
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'waiting_to_arrive', 'queue_number' => null, 'dropped_off_at' => null]);
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('confirmed_size')->count());
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', ['groomers_on_duty' => 2])->assertOk();
        $this->postJson('/api/admin/bookings/1/check-in', $sizes)->assertOk()->assertJsonPath('queue_number', 1);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_min' => 45, 'grooming_estimate_max' => 60]);
        $this->assertSame([1, 2], DB::table('booking_pets')->orderBy('booking_pet_id')->pluck('pet_queue_number')->all());
    }

    public function test_workload_forecast_windows_reopen_after_groomer_count_changes(): void
    {
        Carbon::setTestNow('2026-07-24 15:00:00');
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->update(['grooming_estimate_min' => 45, 'grooming_estimate_max' => 60]);
        DB::table('clinic_settings')->update(['groomers_on_duty' => 1, 'grooming_prereg_cutoff_time' => '17:00:00']);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '3:00 PM - 4:00 PM', 'start_time' => '15:00:00', 'end_time' => '16:00:00']);
        $this->authenticateAs('customer');
        $payload = ['date' => now()->toDateString(), 'pets' => [['species' => 'dog', 'size' => 'small', 'services' => ['package' => 'partial_grooming']]]];
        $this->postJson('/api/booking/workload-forecast', $payload)->assertOk()
            ->assertJsonPath('windows.0.is_workload_unavailable', true)
            ->assertJsonPath('windows.0.workload_message', 'Unavailable — not enough grooming time remaining');
        $this->authenticateAs('staff');
        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', ['groomers_on_duty' => 2])->assertOk();
        $this->authenticateAs('customer');
        $this->postJson('/api/booking/workload-forecast', $payload)->assertOk()->assertJsonPath('windows.0.is_workload_unavailable', false);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(2, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        $this->assertSame(60, DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_estimate_max'));
    }

    public function test_workload_pre_registration_forecast_does_not_guarantee_later_check_in(): void
    {
        Carbon::setTestNow('2026-07-24 13:00:00');
        DB::table('clinic_settings')->update(['groomers_on_duty' => 1, 'grooming_prereg_cutoff_time' => '17:00:00']);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '1:00 PM - 2:00 PM', 'start_time' => '13:00:00', 'end_time' => '14:00:00']);
        $this->authenticateAs('customer');
        $pet = ['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small', 'services' => ['package' => 'partial_grooming']];
        $this->postJson('/api/booking/workload-forecast', ['date' => now()->toDateString(), 'pets' => [$pet]])
            ->assertOk()->assertJsonPath('windows.0.is_workload_unavailable', false);
        $booking = $this->postJson('/api/booking/store', ['booking_date' => now()->toDateString(), 'window_id' => 1,
            'number_of_pets' => 1, 'pets' => [$pet]])->assertCreated()->json('booking.booking_id');
        $this->assertDatabaseHas('bookings', ['booking_id' => $booking, 'queue_number' => null, 'status' => 'waiting_to_arrive']);
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        Carbon::setTestNow(now()->setTime(14, 0));
        DB::table('bookings')->insert(['booking_id' => 999, 'booking_reference' => 'LIVE-WORKLOAD-999', 'booking_date' => now()->toDateString(), 'status' => 'in_progress', 'queue_number' => 1]);
        DB::table('booking_pets')->insert(['booking_pet_id' => 999, 'booking_id' => 999, 'grooming_state' => 'in_progress',
            'grooming_start_time' => now(), 'grooming_estimate_min' => 120, 'grooming_estimate_max' => 240]);
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/'.$booking.'/check-in')->assertUnprocessable()->assertJsonPath('code', 'insufficient_grooming_time');
        $this->assertDatabaseHas('bookings', ['booking_id' => $booking, 'booking_date' => now()->toDateString(), 'status' => 'waiting_to_arrive', 'queue_number' => null]);
        $this->assertDatabaseCount('bookings', 2);
        DB::table('booking_pets')->where('booking_pet_id', 999)->update(['grooming_end_time' => now(), 'grooming_state' => 'finished']);
        $this->postJson('/api/admin/bookings/'.$booking.'/check-in')->assertOk()->assertJsonPath('queue_number', 2);
    }

    public function test_workload_staff_factors_are_recalculated_before_admission_and_rollback_on_rejection(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('booking_services')->update(['service_id' => 90]);
        DB::table('booking_pets')->update(['registered_size' => 'small', 'grooming_estimate_min' => 45,
            'grooming_estimate_max' => 60, 'pet_queue_number' => null, 'pet_queue_date' => null]);
        DB::table('bookings')->update(['status' => 'waiting_to_arrive', 'queue_number' => null]);
        Carbon::setTestNow(now()->setTime(15, 0));
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => 'small', 'estimate_factors' => ['matted_tangled']],
            ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertUnprocessable()->assertJsonPath('code', 'insufficient_grooming_time');
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_estimate_max' => 60, 'confirmed_size' => null]);
    }

    public function test_workload_warnings_do_not_interrupt_active_sessions_or_block_admitted_starts(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->update(['grooming_estimate_min' => 45, 'grooming_estimate_max' => 60]);
        Carbon::setTestNow(now()->setTime(16, 30));
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('groomingWorkload.state', 'Needs staff action');
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')->assertOk();
        $this->postJson('/api/admin/bookings/1/pets/2/start-grooming')->assertOk();
        $before = DB::table('booking_pets')->orderBy('booking_pet_id')->get()->toArray();
        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', ['groomers_on_duty' => 1])->assertOk();
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('groomingWorkload.in_progress', 2)
            ->assertJsonPath('groomingWorkload.groomers_on_duty', 1)->assertJsonPath('groomerCapacity.available_slots', 0);
        $this->assertEquals($before, DB::table('booking_pets')->orderBy('booking_pet_id')->get()->toArray());
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'in_progress', 'queue_number' => 1]);
    }

    public function test_workload_walkin_rejection_creates_no_replacement_or_queue_records(): void
    {
        $this->authenticateAs('staff');
        Carbon::setTestNow(now()->setTime(16, 30));
        $this->postJson('/api/admin/walk-in', ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small', 'services' => [['service_slug' => 'partial_grooming']]]],
            'sedation_consent' => false, 'terms_agreed' => true])->assertUnprocessable()->assertJsonPath('code', 'insufficient_grooming_time');
        foreach (['bookings', 'booking_pets', 'walkins', 'pets', 'unregistered_customers'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_workload_pre_registration_rejection_rolls_back_and_future_date_ignores_todays_workload(): void
    {
        Carbon::setTestNow('2026-07-24 13:00:00');
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->update(['grooming_estimate_min' => 120, 'grooming_estimate_max' => 240]);
        DB::table('clinic_settings')->update(['groomers_on_duty' => 1]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '1:00 PM - 2:00 PM', 'start_time' => '13:00:00', 'end_time' => '14:00:00']);
        $this->authenticateAs('customer');
        $payload = ['booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small', 'services' => ['package' => 'partial_grooming']]]];
        $this->postJson('/api/booking/store', $payload)->assertUnprocessable()->assertJsonPath('code', 'grooming_forecast_unavailable');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('pets', 2);
        $this->assertDatabaseCount('notifications', 0);
        $payload['booking_date'] = now()->addDay()->toDateString();
        $this->postJson('/api/booking/store', $payload)->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
    }

    #[DataProvider('authorizedGroomingRoles')]
    public function test_walkin_capacity_preview_uses_live_workload_and_all_new_pets_without_writes(string $role): void
    {
        $this->authenticateAs($role);
        Carbon::setTestNow(now()->setTime(15, 0));
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->update(['grooming_estimate_min' => 60, 'grooming_estimate_max' => 60]);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['grooming_state' => 'in_progress',
            'grooming_start_time' => now()->subMinutes(30), 'grooming_estimate_max' => 90]);
        $pet = ['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small',
            'services' => [['service_slug' => 'partial_grooming']]];
        $before = [];
        foreach (['bookings', 'booking_pets', 'pets', 'walkins', 'unregistered_customers', 'booking_services', 'customer_notifications'] as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }
        foreach ([1 => false, 2 => true] as $groomers => $fits) {
            DB::table('clinic_settings')->update(['groomers_on_duty' => $groomers]);
            $this->postJson('/api/admin/walk-in/capacity-preview', ['pets' => [$pet, [...$pet, 'pet_name' => 'Bruno']]])
                ->assertOk()->assertJsonPath('capacity.fits', $fits)
                ->assertJsonPath('capacity.waiting', 1)->assertJsonPath('capacity.in_progress', 1)
                ->assertJsonPath('capacity.projected_last_completion', now()->setTime($fits ? 17 : 19, 0)->toIso8601String())
                ->assertJsonPath('capacity.closing_time', now()->setTime(17, 0)->toIso8601String());
        }
        foreach ($before as $table => $rows) $this->assertEquals($rows, DB::table($table)->get()->toArray(), $table);
    }

    public function test_walkin_capacity_preview_uses_verified_size_and_staff_factors_without_updating_pet(): void
    {
        $this->authenticateAs('staff');
        Carbon::setTestNow(now()->setTime(15, 45));
        DB::table('users')->insert(['user_id' => 3, 'role' => 'customer']);
        DB::table('pets')->insert(['pet_id' => 1, 'user_id' => 3, 'pet_name' => 'Coco', 'species' => 'dog',
            'size' => 'extra_large', 'clinic_verified_fields' => '["size"]']);
        $pet = ['pet_id' => 1, 'pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small', 'weight' => 8,
            'services' => [['service_slug' => 'partial_grooming']]];
        $payload = ['owner_record_type' => 'registered', 'customer_user_id' => 3, 'pets' => [$pet]];
        $this->postJson('/api/admin/walk-in/capacity-preview', $payload)->assertOk()
            ->assertJsonPath('capacity.fits', false)
            ->assertJsonPath('capacity.projected_last_completion', now()->setTime(17, 30)->toIso8601String());
        $payload['pets'][0]['estimate_factors'] = ['matted_tangled'];
        $this->postJson('/api/admin/walk-in/capacity-preview', $payload)->assertOk()
            ->assertJsonPath('capacity.projected_last_completion', now()->setTime(19, 45)->toIso8601String());
        $this->assertDatabaseHas('pets', ['pet_id' => 1, 'size' => 'extra_large', 'weight' => null]);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_walkin_capacity_preview_does_not_guarantee_final_admission_or_reserve_queue_numbers(): void
    {
        $this->authenticateAs('staff');
        Carbon::setTestNow(now()->setTime(15, 0));
        DB::table('clinic_settings')->update(['groomers_on_duty' => 1]);
        $pet = ['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'small',
            'services' => [['service_slug' => 'partial_grooming']]];
        $this->postJson('/api/admin/walk-in/capacity-preview', ['pets' => [$pet]])
            ->assertOk()->assertJsonPath('capacity.fits', true)
            ->assertJsonPath('capacity.pets.walkin-0.projected_start', now()->toIso8601String());
        foreach (['bookings', 'booking_pets', 'walkins', 'pets'] as $table) $this->assertDatabaseCount($table, 0);
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->update(['grooming_estimate_min' => 60, 'grooming_estimate_max' => 60]);
        $payload = ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567', 'pets' => [$pet],
            'sedation_consent' => false, 'terms_agreed' => true];
        $this->postJson('/api/admin/walk-in', $payload)->assertUnprocessable()->assertJsonPath('code', 'insufficient_grooming_time');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('walkins', 0);
        $this->assertDatabaseCount('pets', 2);
        $this->assertSame([1, 2], DB::table('booking_pets')->pluck('pet_queue_number')->all());
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $this->postJson('/api/admin/walk-in', $payload)->assertCreated()->assertJsonPath('queue_number', 2)
            ->assertJsonPath('pets.0.pet_queue_number', 3);
    }

    private function authenticateAs(string $role): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => match ($role) {
                'admin' => 1,
                'staff' => 2,
                default => 3,
            },
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function seedMultiPetBooking(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'MULTI-PET-SECURITY',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'dropped_off_at' => now(),
        ]);
        DB::table('pets')->insert([
            [
                'pet_id' => 1,
                'pet_name' => 'Zeus',
                'species' => 'dog',
            ],
            [
                'pet_id' => 2,
                'pet_name' => 'Ginger',
                'species' => 'cat',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'pet_queue_date' => now()->toDateString(),
                'pet_queue_number' => 1,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'pet_queue_date' => now()->toDateString(),
                'pet_queue_number' => 2,
            ],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 1,
                'booking_id' => 1,
                'booking_pet_id' => 1,
                'service_id' => 1,
                'price_at_booking' => 250,
            ],
            [
                'booking_service_id' => 2,
                'booking_id' => 1,
                'booking_pet_id' => 2,
                'service_id' => 1,
                'price_at_booking' => 250,
            ],
        ]);
    }
}
