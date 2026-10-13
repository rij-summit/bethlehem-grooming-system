<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicCharge;
use App\Models\ClinicService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicCaseServiceBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role');
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('species');
            $table->string('size')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->json('clinic_verified_fields')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->unsignedInteger('pet_id')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
        });
        (require database_path('migrations/2026_10_10_000001_create_clinic_charges_table.php'))->up();
        (require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php'))->up();
        (require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php'))->up();
        DB::table('pets')->insert(['pet_id' => 1, 'species' => 'dog', 'weight' => 15, 'size' => 'small']);
        ClinicAppointment::create(['status' => 'in_consultation', 'pet_id' => 1]);
        $this->signIn();
    }

    private function signIn(string $subrole = 'veterinarian', int $id = 1): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $id, 'role' => $subrole === 'admin' ? 'admin' : 'staff',
            'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : 'clinic',
            'staff_subrole' => $subrole, 'is_active' => true,
        ]);
        DB::table('users')->updateOrInsert(['user_id' => $id], $user->getAttributes());
        Sanctum::actingAs($user);
    }

    private function catalog(array $changes = []): ClinicService
    {
        return ClinicService::create(array_replace([
            'name' => 'CBC', 'category_id' => 2, 'type' => 'In House', 'identity_key' => hash('sha256', Str::uuid()),
            'pricing_method' => 'fixed', 'billing_unit' => 'sample', 'price' => 500, 'is_active' => true,
        ], $changes));
    }

    private function payload(ClinicService $service, string $status = 'performed', array $changes = []): array
    {
        return array_replace(['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid(), 'status' => $status], $changes);
    }

    public function test_ordered_service_and_cancelled_order_preserve_history_without_charges(): void
    {
        $payload = $this->payload($this->catalog(), 'ordered', ['notes' => 'Order CBC']);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'cancelled'])->assertOk();
        $entry = ClinicCaseService::findOrFail($id);
        $this->assertNotNull($entry->ordered_at);
        $this->assertNotNull($entry->cancelled_at);
        $this->assertSame(1, $entry->cancelled_by_user_id);
        $this->assertSame('Order CBC', $entry->notes);
        $this->assertNull($entry->applied_price);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertEquals(0, ClinicAppointment::find(1)->total_amount);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed'])->assertUnprocessable();
    }

    public function test_direct_cancelled_service_creates_no_charge(): void
    {
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog(), 'cancelled'))
            ->assertCreated()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_fixed_service_generates_one_charge_and_retries_after_payment_do_not_duplicate_it(): void
    {
        $payload = $this->payload($this->catalog(), changes: ['applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.applied_price', '500.00')->assertJsonPath('data.charge.amount', '500.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed'])->assertOk();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        $this->assertSame(1, ClinicCaseService::find($id)->performed_by_user_id);
    }

    public function test_ordered_to_performed_uses_price_at_performance_and_delayed_order_retry_cannot_undo_it(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service, 'ordered', ['quantity' => 2]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $service->update(['price' => 750]);
        $this->signIn(id: 2);
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed'])
            ->assertOk()->assertJsonPath('data.charge.amount', '1500.00');
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['status' => 'performed']))->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.status', 'performed');
        $this->assertDatabaseCount('clinic_charges', 1);
        $entry = ClinicCaseService::find($id);
        $this->assertSame(1, $entry->recorded_by_user_id);
        $this->assertSame(2, $entry->performed_by_user_id);
        $this->assertNotNull($entry->ordered_at);
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'cancelled'])->assertUnprocessable();
    }

    public function test_catalog_changes_preserve_completed_case_charge_and_clinical_snapshots(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $snapshot = ClinicCaseService::find($id)->service_snapshot;
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $service->update(['name' => 'New CBC', 'price' => 900, 'type' => null, 'billing_unit' => 'pet', 'is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->assertSame($snapshot, ClinicCaseService::find($id)->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['description' => 'CBC', 'unit_price' => 500, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_tiered_price_uses_case_weight_and_existing_verified_size_rules(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size', 'tiers' => [
            ['size' => 'small', 'price' => 300], ['size' => 'medium', 'price' => 600], ['size' => 'large', 'price' => 800],
        ]]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 30]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '800.00')->assertJsonPath('data.service_snapshot.pet_size', 'large');
        DB::table('pets')->where('pet_id', 1)->update(['clinic_verified_fields' => json_encode(['size'])]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '300.00')->assertJsonPath('data.service_snapshot.pet_size', 'small');
        DB::table('pets')->where('pet_id', 1)->update(['size' => 'extra_large']);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_case_services', 2);
        $this->assertDatabaseCount('clinic_charges', 2);
    }

    public function test_range_and_starting_at_require_a_final_price_within_catalog_rules(): void
    {
        foreach (['range', 'starting_at'] as $method) {
            $service = $this->catalog(['pricing_method' => $method, 'price' => 500, 'min_price' => 500, 'max_price' => 700]);
            foreach ([null, 499.99, 100000.01, '500.001', ...($method === 'range' ? [700.01] : [])] as $price) {
                $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => $price]))
                    ->assertUnprocessable()->assertJsonValidationErrors('applied_price');
            }
            $ordered = $this->payload($service, 'ordered');
            $id = $this->postJson('/api/admin/clinic-cases/1/services', $ordered)->assertCreated()->json('data.id');
            $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed'])->assertUnprocessable();
            $this->assertSame('ordered', ClinicCaseService::find($id)->status);
            $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed', 'applied_price' => 650, 'quantity' => 1.5])
                ->assertOk()->assertJsonPath('data.charge.amount', '975.00');
        }
        $this->assertDatabaseCount('clinic_charges', 2);
    }

    public function test_billing_edits_preserve_clinical_truth_and_retry_does_not_restore_original_charge(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $before = ClinicCaseService::find($id)->getAttributes();
        foreach (['veterinarian', 'clinic_receptionist', 'admin'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk();
            $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 400, 'quantity' => 2, 'status' => 'cancelled', 'paid' => true])
                ->assertOk()->assertJsonPath('data.amount', '800.00');
            $this->assertSame($before, ClinicCaseService::find($id)->getAttributes());
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => false, 'total_amount' => 800]);
        }
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.charge.amount', '800.00');
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_receptionist_can_view_services_but_cannot_mutate_them_and_vet_cannot_pay(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 500, 'payment_method' => 'cash'])->assertForbidden();
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertForbidden();
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'cancelled'])->assertForbidden();
        $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnosis' => 'Unauthorized'])->assertForbidden();
        $this->signIn('grooming_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/services')->assertForbidden();
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertForbidden();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 0])->assertForbidden();
    }

    public function test_completed_cases_reject_new_services_and_financial_edits(): void
    {
        $payload = $this->payload($this->catalog());
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $this->signIn('admin');
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_inactive_services_request_key_collisions_and_cross_case_updates_are_rejected(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        ClinicAppointment::create(['status' => 'checked_in', 'pet_id' => 1]);
        $this->postJson('/api/admin/clinic-cases/2/services', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['clinic_service_id' => $this->catalog()->id]))->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-cases/2/services/'.$id, ['status' => 'performed'])->assertNotFound();
        $this->patchJson('/api/admin/clinic-cases/2/charges/1', ['unit_price' => 0])->assertNotFound();
        $service->update(['is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_overflow_rolls_back_clinical_transition_charge_and_case_total(): void
    {
        $payload = $this->payload($this->catalog(), 'ordered', ['quantity' => 99999999.99]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed'])->assertUnprocessable();
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id, 'status' => 'ordered', 'applied_price' => null, 'performed_at' => null]);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertEquals(0, ClinicAppointment::find(1)->total_amount);
        $this->patchJson('/api/admin/clinic-cases/1/services/'.$id, ['status' => 'performed', 'quantity' => 1])->assertOk();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['quantity' => 99999999.99])->assertUnprocessable();
        $this->assertDatabaseHas('clinic_charges', ['quantity' => 1, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_database_prevents_two_charges_for_the_same_service_occurrence(): void
    {
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated();
        $duplicate = ClinicCharge::first()->getAttributes();
        unset($duplicate['id']);
        $this->expectException(QueryException::class);
        DB::table('clinic_charges')->insert($duplicate);
    }

    public function test_case_total_overflow_rolls_back_an_otherwise_valid_charge(): void
    {
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Existing charges',
            'quantity' => 1, 'unit_price' => 99999500, 'amount' => 99999500,
        ]);
        ClinicAppointment::find(1)->update(['total_amount' => 99999500]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertEquals(99999500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_foreign_keys_protect_service_history_from_catalog_or_case_deletion(): void
    {
        $service = $this->catalog();
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated();
        foreach ([fn () => $service->delete(), fn () => ClinicAppointment::find(1)->delete(), fn () => ClinicCaseService::first()->delete()] as $delete) {
            try {
                $delete();
                $this->fail('A referenced service occurrence must not be deleted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('clinic_case_services', 1);
                $this->assertDatabaseCount('clinic_charges', 1);
            }
        }
    }

    public function test_service_history_survives_removing_a_financial_line(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->json('data.id');
        ClinicCharge::first()->delete();
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id, 'status' => 'performed', 'applied_price' => 500]);
    }

    public function test_new_routes_use_separate_clinical_billing_and_payment_middleware(): void
    {
        foreach ([
            ['POST', 'api/admin/clinic-cases/{id}/services', 'clinic:clinical'],
            ['PATCH', 'api/admin/clinic-cases/{id}/services/{caseServiceId}', 'clinic:clinical'],
            ['GET', 'api/admin/clinic-cases/{id}/charges', 'clinic:billing'],
            ['PATCH', 'api/admin/clinic-cases/{id}/charges/{chargeId}', 'clinic:billing'],
            ['POST', 'api/admin/clinic-appointments/{id}/pay', 'clinic:payment'],
        ] as [$method, $uri, $permission]) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertContains('clinic:access', $route->gatherMiddleware());
        }
    }

    public function test_migration_can_be_rolled_back_without_changing_legacy_charges(): void
    {
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Legacy fee',
            'quantity' => 1, 'unit_price' => 250, 'amount' => 250,
        ]);
        $migration = require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('clinic_case_services'));
        $this->assertDatabaseHas('clinic_charges', ['description' => 'Legacy fee', 'amount' => 250]);
        $migration->up();
        $this->assertDatabaseHas('clinic_charges', ['description' => 'Legacy fee', 'clinic_case_service_id' => null]);
    }
}
