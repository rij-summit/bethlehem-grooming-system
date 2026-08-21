<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminGroomingMedicalConcernController;
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
        ['PATCH', 'api/admin/clinic/settings/groomers-on-duty'],
        ['POST', 'api/admin/bookings/{id}/check-in'],
        ['POST', 'api/admin/bookings/{id}/revert-check-in'],
        ['POST', 'api/admin/bookings/{id}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/revert-start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/cancel'],
        ['POST', 'api/admin/bookings/{id}/archive'],
        ['POST', 'api/admin/bookings/{id}/picked-up'],
        ['POST', 'api/admin/bookings/{id}/late-check-in'],
        ['GET', 'api/admin/bookings/no-shows'],
        ['GET', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns'],
        ['GET', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}'],
        ['PATCH', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/notify-customer'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/apply-recommended-action'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resume-grooming'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/cancel'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resolve'],
        ['POST', 'api/admin/bookings/{id}/pay'],
        ['POST', 'api/admin/bookings/{id}/pay-now'],
        ['POST', 'api/admin/bookings/{id}/release'],
        ['GET', 'api/admin/transactions'],
        ['POST', 'api/admin/walk-in'],
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
            $table->string('fur_type')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->date('pet_queue_date')->nullable();
            $table->unsignedInteger('pet_queue_number')->nullable();
            $table->text('special_instructions')->nullable();
            $table->unsignedInteger('groomer_id')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20)->default('not_started');
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
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

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

        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('notifications');
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
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'start grooming' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish grooming' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
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
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'start whole booking' => ['POST', '/api/admin/bookings/1/start-grooming'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'start one pet' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish one pet' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'finish whole booking' => ['POST', '/api/admin/bookings/1/mark-done'],
            'staff cancellation' => ['POST', '/api/admin/bookings/1/cancel'],
            'archive booking' => ['POST', '/api/admin/bookings/1/archive'],
            'mark picked up' => ['POST', '/api/admin/bookings/1/picked-up'],
            'late check in no-show' => ['POST', '/api/admin/bookings/1/late-check-in'],
            'no-show listing' => ['GET', '/api/admin/bookings/no-shows'],
            'list medical concerns' => ['GET', '/api/admin/bookings/1/pets/1/medical-concerns'],
            'create medical concern' => ['POST', '/api/admin/bookings/1/pets/1/medical-concerns'],
            'view medical concern' => ['GET', '/api/admin/bookings/1/pets/1/medical-concerns/1'],
            'update medical concern' => ['PATCH', '/api/admin/bookings/1/pets/1/medical-concerns/1'],
            'send medical concern to customer' => ['POST', '/api/admin/bookings/1/pets/1/medical-concerns/1/notify-customer'],
            'cancel medical concern' => ['POST', '/api/admin/bookings/1/pets/1/medical-concerns/1/cancel'],
            'resolve medical concern' => ['POST', '/api/admin/bookings/1/pets/1/medical-concerns/1/resolve'],
            'record final payment' => ['POST', '/api/admin/bookings/1/pay'],
            'record early payment' => ['POST', '/api/admin/bookings/1/pay-now'],
            'release paid booking' => ['POST', '/api/admin/bookings/1/release'],
            'grooming transaction listing' => ['GET', '/api/admin/transactions'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
        ];
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
            ['pet_id' => 100, 'user_id' => 100, 'pet_name' => 'Alpha', 'species' => 'dog'],
            ['pet_id' => 101, 'user_id' => 100, 'pet_name' => 'Beta', 'species' => 'cat'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 100, 'booking_id' => 100, 'pet_id' => 100],
            ['booking_pet_id' => 101, 'booking_id' => 100, 'pet_id' => 101],
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
            10,
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
                        'zero_total',
                        'pets',
                    ],
                    'payment_summary' => [
                        'payment_ready',
                        'payment_blocked_reason',
                        'final_booking_total',
                        'zero_total',
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
                            'paymentReviewRequired',
                            'paymentReviewCompleted',
                            'paymentReviewDecision',
                            'paymentReviewDecisionLabel',
                            'reviewedFinalCharge',
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

        // Non-standard historical records must use the authoritative fallback
        // instead of fabricating a completed stopped-grooming review.
        DB::table('booking_pets')->where('booking_pet_id', 12)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
            'grooming_end_time' => null,
        ]);

        $this->getJson('/api/admin/bookings/archived?search=ARCHIVE-12')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('archived.0.paymentReady', false)
            ->assertJsonPath('archived.0.finalPaymentTotal', null)
            ->assertJsonPath('archived.0.pets.0.paymentReviewRequired', true)
            ->assertJsonPath('archived.0.pets.0.paymentReviewCompleted', false);

        DB::table('booking_pets')->where('booking_pet_id', 12)->update([
            'grooming_state' => '',
            'grooming_end_time' => '2026-07-24 10:00:00',
        ]);

        $this->getJson('/api/admin/bookings/archived?search=ARCHIVE-12')
            ->assertOk()
            ->assertJsonPath('archived.0.paymentReady', false)
            ->assertJsonPath(
                'archived.0.paymentSummary.pets.0.grooming_state',
                BookingPet::GROOMING_STATE_NOT_STARTED,
            );
    }

    #[DataProvider('authorizedGroomingRoles')]
    public function test_staff_and_admin_can_complete_the_existing_core_grooming_workflow(
        string $role,
    ): void {
        $this->authenticateAs($role);
        $this->seedMultiPetBooking();

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('queuedList.0.id', 1);

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
        $this->postJson('/api/admin/bookings/1/pets/2/mark-done')
            ->assertOk()
            ->assertJsonPath('all_pets_finished', true)
            ->assertJsonPath('booking_status', 'for_payment');

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
                    'service_slug' => 'basic-grooming',
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
            'size' => 'small',
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
                    'size' => 'small',
                    'services' => [['service_slug' => 'basic-grooming']],
                ],
                [
                    'pet_name' => 'Luna',
                    'species' => 'Dog',
                    'weight' => 8,
                    'services' => [['service_slug' => 'basic-grooming']],
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
                'services' => [['service_slug' => 'basic-grooming']],
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
                'services' => [['service_slug' => 'basic-grooming']],
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
                'services' => [['service_slug' => 'basic-grooming']],
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
            [
                'booking_id' => 91,
                'booking_reference' => 'CAPACITY-STOPPED',
                'user_id' => 90,
                'booking_date' => now()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'checked_in',
                'cancellation_reason' => null,
                'cancel_count' => 0,
                'dropped_off_at' => now(),
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 90,
                'booking_id' => 90,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
            [
                'booking_pet_id' => 91,
                'booking_id' => 91,
                'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
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
            AdminGroomingMedicalConcernController::class,
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
            ['GET', 'api/pets/{petId}/medical-concerns'],
            ['GET', 'api/pets/{petId}/medical-concerns/{publicId}'],
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
