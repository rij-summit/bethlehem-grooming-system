<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicConfinement;
use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicConfinementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role'); $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable(); $table->string('first_name')->nullable();
            $table->string('last_name')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id'); $table->string('pet_name'); $table->string('species');
            $table->decimal('weight', 5, 2)->nullable(); $table->string('size')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id(); $table->string('appointment_reference'); $table->string('status');
            $table->string('case_type')->default('consultation'); $table->unsignedInteger('pet_id');
            $table->unsignedInteger('user_id')->nullable(); $table->date('appointment_date')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0); $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable(); $table->timestamp('consultation_finished_at')->nullable(); $table->timestamps();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique();
            $table->unsignedInteger('primary_veterinarian_id')->nullable(); $table->string('primary_veterinarian_name')->nullable();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique(); $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable(); $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable(); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id'); $table->string('type'); $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable(); $table->text('message');
            $table->boolean('is_read')->default(false); $table->timestamp('created_at')->nullable();
        });
        Schema::create('clinic_attachments', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('clinic_record_id'); });
        foreach (['2026_10_10_000001_create_clinic_charges_table.php', '2026_10_13_000001_create_clinic_services_table.php',
            '2026_10_13_000002_create_clinic_case_services_table.php', '2026_10_13_000004_create_clinic_confinements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->signIn();
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'species' => 'dog', 'weight' => 1, 'size' => 'small']);
        ClinicAppointment::create(['appointment_reference' => 'CL-1', 'status' => 'in_consultation', 'pet_id' => 1]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'primary_veterinarian_id' => 1, 'primary_veterinarian_name' => 'Attending Vet']);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.4, 'temperature_c' => 38,
            'heart_rate_bpm' => 90, 'respiratory_rate_bpm' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signIn(string $subrole = 'veterinarian'): void
    {
        $user = new User;
        $user->forceFill(['user_id' => 1, 'role' => $subrole === 'admin' ? 'admin' : 'staff', 'staff_type' => 'clinic',
            'staff_subrole' => $subrole, 'is_active' => true, 'first_name' => 'Attending', 'last_name' => 'Vet']);
        DB::table('users')->updateOrInsert(['user_id' => 1], $user->getAttributes());
        Sanctum::actingAs($user);
    }

    private function service(array $changes = []): ClinicService
    {
        return ClinicService::create(array_replace(['name' => 'Confinement',
            'category_id' => ClinicServiceCategory::where('name', 'Boarding & Lodging')->value('id'),
            'identity_key' => hash('sha256', Str::uuid()), 'pricing_method' => 'fixed', 'billing_unit' => 'day',
            'price' => 500, 'is_active' => true], $changes));
    }

    private function admit(?ClinicService $service = null): array
    {
        $payload = ['clinic_service_id' => ($service ?? $this->service())->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertCreated();
        return $payload;
    }

    public function test_admission_links_one_ordered_service_to_the_same_case_and_retries_are_idempotent(): void
    {
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_appointments', 1); $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertDatabaseCount('clinic_case_services', 1); $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'in_consultation']);
        $this->assertDatabaseHas('clinic_case_services', ['status' => 'ordered']);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.attending_veterinarian_name', 'Attending Vet');
    }

    public function test_admission_requires_ongoing_case_primary_veterinarian_and_appropriate_catalogue_configuration(): void
    {
        $service = $this->service();
        $payload = ['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        foreach (['checked_in', 'for_payment', 'completed'] as $status) {
            ClinicAppointment::find(1)->update(['status' => $status]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
        }
        ClinicAppointment::find(1)->update(['status' => 'in_consultation']);
        foreach (['paid' => true, 'archived_at' => now()] as $field => $value) {
            ClinicAppointment::find(1)->update([$field => $value]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
            ClinicAppointment::find(1)->update([$field => $field === 'paid' ? false : null]);
        }
        DB::table('clinic_records')->update(['primary_veterinarian_id' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        DB::table('clinic_records')->update(['primary_veterinarian_id' => 1]);
        foreach ([['billing_unit' => 'sample'], ['billing_unit' => 'day', 'is_active' => false],
            ['is_active' => true, 'category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]] as $change) {
            $service->update($change);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('clinic_service_id');
        }
        $this->assertDatabaseCount('clinic_confinements', 0); $this->assertDatabaseCount('clinic_case_services', 0);
    }

    public function test_monitoring_is_append_only_chronological_idempotent_and_does_not_overwrite_vitals(): void
    {
        $this->admit(); $before = DB::table('clinic_vitals')->first();
        $payload = ['request_key' => (string) Str::uuid(), 'temperature_c' => 39.1, 'notes' => 'Alert'];
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk();
        Carbon::setTestNow(now()->addHour());
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'heart_rate_bpm' => 95])->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk()->assertJsonPath('data.0.notes', 'Alert')->assertJsonPath('data.1.heart_rate_bpm', 95);
        $this->assertEquals($before, DB::table('clinic_vitals')->first()); $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_receptionist_can_view_but_cannot_admit_monitor_or_discharge_and_vet_cannot_pay(): void
    {
        $this->admit(); $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk();
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk();
        foreach (['', '/observations', '/discharge'] as $suffix) $this->postJson('/api/admin/clinic-cases/1/confinement'.$suffix)->assertForbidden();
        $this->signIn(); $this->postJson('/api/admin/clinic-appointments/1/pay')->assertForbidden();
    }

    public function test_normal_finish_and_direct_confinement_service_mutations_are_blocked(): void
    {
        $this->admit();
        foreach (['/api/admin/clinic-cases/1/finish', '/api/admin/clinic-appointments/1/finish-consultation'] as $url)
            $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = ClinicCaseService::first()->id;
        foreach (['performed', 'cancelled'] as $status) $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => $status])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_discharge_finalizes_one_service_one_charge_and_one_payment_due_transition(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addHours(6));
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 1])->assertOk()
            ->assertJsonPath('data.case_service.applied_price', '500.00')->assertJsonPath('data.case_service.quantity', '1.00');
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 1); $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'total_amount' => 500]);
        $this->assertNotNull(ClinicConfinement::first()->discharged_at);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'notes' => 'Late'])->assertUnprocessable();
    }

    public function test_exact_elapsed_duration_rules_and_one_time_units(): void
    {
        $rule = app(\App\Services\ConfinementBillingDuration::class); $start = now();
        foreach ([6 * 3600 => 1, 86400 => 1, 86460 => 2, 172800 => 2] as $seconds => $days)
            $this->assertSame($days, $rule->billableDays($start, $start->copy()->addSeconds($seconds)));
        $this->admit($this->service(['billing_unit' => 'visit'])); Carbon::setTestNow(now()->addDays(3));
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()->assertJsonPath('quantity', 1);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()->assertJsonPath('data.case_service.quantity', '1.00');
    }

    public function test_day_quantity_is_derived_by_server_and_cannot_be_supplied_by_client(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addDay()->addMinute());
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()->assertJsonPath('quantity', 2);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['quantity' => 1])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()->assertJsonPath('data.case_service.charge.amount', '1000.00');
    }

    public function test_variable_discharge_prices_follow_existing_range_and_starting_at_rules(): void
    {
        $service = $this->service(['pricing_method' => 'range', 'price' => null, 'min_price' => 800, 'max_price' => 1500]);
        $this->admit($service);
        foreach ([null, 799.99, 1500.01, '1000.001'] as $price)
            $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => $price])->assertUnprocessable();
        $this->assertNull(ClinicConfinement::first()->discharged_at); $this->assertDatabaseCount('clinic_charges', 0);
        $service->update(['pricing_method' => 'starting_at', 'price' => 1000]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 999.99])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 1800])->assertOk()->assertJsonPath('data.case_service.applied_price', '1800.00');
    }

    public function test_tiered_discharge_uses_saved_case_weight_and_preserves_historical_snapshot_after_payment(): void
    {
        $service = $this->service(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size', 'tiers' => [['size' => 'medium', 'price' => 600]]]);
        $payload = $this->admit($service);
        DB::table('clinic_vitals')->update(['weight_kg' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable();
        DB::table('clinic_vitals')->update(['weight_kg' => 18.4]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 1])->assertOk()
            ->assertJsonPath('data.case_service.applied_price', '600.00')->assertJsonPath('data.case_service.service_snapshot.pet_size', 'medium');
        $this->signIn('clinic_receptionist');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 600, 'payment_method' => 'cash'])->assertOk();
        $snapshot = ClinicCaseService::first()->service_snapshot;
        $service->update(['pricing_method' => 'fixed', 'price' => 900, 'is_active' => false]); DB::table('clinic_vitals')->update(['weight_kg' => 4]);
        $this->signIn('admin');
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertSame($snapshot, ClinicCaseService::first()->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 600]);
    }

    public function test_failed_discharge_rolls_back_episode_service_charge_and_total(): void
    {
        $this->admit(); DB::table('clinic_vitals')->update(['temperature_c' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable();
        $this->assertNull(ClinicConfinement::first()->discharged_at); $this->assertSame('ordered', ClinicCaseService::first()->status);
        $this->assertDatabaseCount('clinic_charges', 0); $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('clinic_appointments', ['status' => 'in_consultation', 'total_amount' => 0]);
    }

    public function test_database_prevents_duplicate_episodes(): void
    {
        $this->admit(); $attributes = ClinicConfinement::first()->getAttributes(); unset($attributes['id']);
        $this->expectException(QueryException::class); DB::table('clinic_confinements')->insert($attributes);
    }

    public function test_confined_list_and_count_use_active_episodes_and_discharge_retains_viewable_history(): void
    {
        $this->admit();
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.status', 'in_consultation')
            ->assertJsonPath('data.0.confinement.case_service.status', 'ordered');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 1);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)->assertJsonPath('cases.0.status', 'for_payment');
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.case_service.status', 'performed');
    }

    public function test_catalogue_options_filter_category_active_state_and_supported_units_without_name_matching(): void
    {
        $eligible = $this->service(['name' => 'Renamed hospital stay']);
        $this->service(['is_active' => false]); $this->service(['billing_unit' => 'sample']);
        $this->service(['category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]);
        $this->getJson('/api/admin/clinic-confinement-service-options')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $eligible->id);
        $this->signIn('admin'); $this->admit($eligible);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'notes' => 'Admin monitoring'])->assertCreated();
        $this->postJson('/api/admin/clinic-appointments/1/cancel')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
