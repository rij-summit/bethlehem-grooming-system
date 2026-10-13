<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicCharge;
use App\Models\ClinicService;
use App\Models\User;
use App\Services\ClinicBillingService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    private function payload(ClinicService $service, array $changes = []): array
    {
        return array_replace(['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid()], $changes);
    }

    public function test_manual_service_is_performed_immediately_with_notes_and_server_price(): void
    {
        $service = $this->catalog();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$service->id)->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00');
        $service->update(['price' => 650]);
        $payload = $this->payload($service, ['notes' => 'Blood test requested', 'applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'performed')->assertJsonPath('data.quantity', '1.00')
            ->assertJsonPath('data.notes', 'Blood test requested')->assertJsonPath('data.applied_price', '650.00')
            ->assertJsonPath('data.charge.amount', '650.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 650]);
        $entry = ClinicCaseService::findOrFail($id);
        $this->assertNull($entry->ordered_at);
        $this->assertNotNull($entry->performed_at);
        $this->assertEquals(650, ClinicAppointment::find(1)->total_amount);
    }

    public function test_manual_api_rejects_client_status_and_quantity_and_has_no_transition_endpoint(): void
    {
        $service = $this->catalog();
        foreach (['ordered', 'performed', 'cancelled'] as $status) {
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['status' => $status]))
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['quantity' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson('/api/admin/clinic-cases/1/services/1', ['status' => 'performed'])->assertStatus(405);
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_removing_manual_service_deletes_only_its_charge_and_recalculates_total(): void
    {
        $service = $this->catalog();
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated()->json('data.id');
        $otherId = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated()->json('data.id');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk()->assertJsonPath('message', 'Service removed.');
        $this->assertDatabaseMissing('clinic_case_services', ['id' => $id]);
        $this->assertDatabaseMissing('clinic_charges', ['clinic_case_service_id' => $id]);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $otherId, 'amount' => 500]);
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 500);
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertNotFound();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$otherId)->assertOk();
        $this->assertEquals(0, ClinicAppointment::find(1)->total_amount);
    }

    public function test_paid_archived_and_locked_cases_cannot_remove_a_service(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated()->json('data.id');
        $this->signIn('admin');
        foreach ([['paid' => true], ['archived_at' => now()], ['status' => 'completed'], ['status' => 'cancelled'],
            ['status' => 'no_show'], ['status' => 'expired'], ['status' => 'pending']] as $lock) {
            ClinicAppointment::find(1)->update(['paid' => false, 'archived_at' => null, 'status' => 'in_consultation', ...$lock]);
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
            $this->assertDatabaseHas('clinic_case_services', ['id' => $id]);
            $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
            $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        }
        ClinicAppointment::find(1)->update(['status' => 'for_payment']);
        $this->signIn();
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
        $this->signIn('admin');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk();
    }

    public function test_removal_rolls_back_both_records_when_total_update_fails(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated()->json('data.id');
        $this->mock(ClinicBillingService::class)->shouldReceive('updateTotal')->once()
            ->andThrow(ValidationException::withMessages(['charges' => 'Unable to update total.']));
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id]);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_fixed_service_generates_one_charge_and_retries_after_payment_do_not_duplicate_it(): void
    {
        $payload = $this->payload($this->catalog(), changes: ['applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.applied_price', '500.00')->assertJsonPath('data.charge.amount', '500.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        $this->assertSame(1, ClinicCaseService::find($id)->performed_by_user_id);
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
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()
            ->assertJsonPath('data.0.service_snapshot.name', 'CBC')->assertJsonPath('data.0.applied_price', '500.00');
        $this->assertSame($snapshot, ClinicCaseService::find($id)->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['description' => 'CBC', 'unit_price' => 500, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_tiered_price_uses_only_current_case_weight_despite_conflicting_verified_profile_size(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size', 'tiers' => [
            ['size' => 'small', 'price' => 300], ['size' => 'medium', 'price' => 600], ['size' => 'large', 'price' => 800],
        ]]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 30]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '800.00')->assertJsonPath('data.service_snapshot.pet_size', 'large');
        DB::table('pets')->where('pet_id', 1)->update(['clinic_verified_fields' => json_encode(['size'])]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '800.00')->assertJsonPath('data.service_snapshot.pet_size', 'large');
        DB::table('pets')->where('pet_id', 1)->update(['size' => 'extra_large']);
        DB::table('clinic_vitals')->where('clinic_appointment_id', 1)->update(['weight_kg' => 70]);
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
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['applied_price' => 650]))
                ->assertCreated()->assertJsonPath('data.charge.amount', '650.00');
        }
        $this->assertDatabaseCount('clinic_charges', 2);
    }

    public function test_service_generated_prices_and_quantities_are_read_only_for_every_billing_role(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $before = ClinicCaseService::find($id)->getAttributes();
        foreach (['veterinarian', 'clinic_receptionist', 'admin'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk();
            foreach ([['unit_price' => 400], ['unit_price' => 600], ['unit_price' => 0], ['quantity' => 2],
                ['amount' => 0, 'clinic_case_service_id' => null]] as $change) {
                $this->patchJson('/api/admin/clinic-cases/1/charges/1', $change)
                    ->assertUnprocessable()->assertJsonValidationErrors('charges');
            }
            $this->assertSame($before, ClinicCaseService::find($id)->getAttributes());
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => false, 'total_amount' => 500]);
        }
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.charge.amount', '500.00');
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_unlinked_medicine_vaccine_and_legacy_charge_behavior_is_preserved(): void
    {
        $this->signIn('clinic_receptionist');
        foreach (['medicine', 'vaccine', 'existing'] as $kind) {
            $id = DB::table('clinic_charges')->insertGetId([
                'clinic_appointment_id' => 1, 'kind' => $kind, 'description' => $kind,
                'quantity' => 1, 'unit_price' => 500, 'amount' => 500,
            ]);
            $this->patchJson('/api/admin/clinic-cases/1/charges/'.$id, ['unit_price' => 600, 'quantity' => 2])
                ->assertOk()->assertJsonPath('data.amount', '1200.00');
        }
        $this->assertEquals(3600, ClinicAppointment::find(1)->total_amount);
    }

    public function test_starting_at_accepts_prices_above_grooming_safety_caps(): void
    {
        $service = $this->catalog(['pricing_method' => 'starting_at', 'price' => 1000]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => 1800]))
            ->assertCreated()->assertJsonPath('data.applied_price', '1800.00');
    }

    public function test_range_boundaries_are_valid_and_resolved_variable_prices_cannot_be_changed_in_billing(): void
    {
        $service = $this->catalog(['pricing_method' => 'range', 'price' => null, 'min_price' => 800, 'max_price' => 1500]);
        foreach ([800, 1500] as $price) {
            $chargeId = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => $price]))
                ->assertCreated()->json('data.charge.id');
            $this->patchJson('/api/admin/clinic-cases/1/charges/'.$chargeId, ['unit_price' => 1200])->assertUnprocessable();
            $this->assertDatabaseHas('clinic_charges', ['id' => $chargeId, 'unit_price' => $price, 'amount' => $price]);
        }
    }

    public function test_tiered_without_valid_saved_case_weight_cannot_use_profile_weight_or_size(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size',
            'tiers' => [['size' => 'small', 'price' => 300], ['size' => 'medium', 'price' => 600]]]);
        DB::table('pets')->where('pet_id', 1)->update(['clinic_verified_fields' => json_encode(['size'])]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        foreach ([null, 0, -1, 156] as $weight) {
            DB::table('clinic_vitals')->updateOrInsert(['clinic_appointment_id' => 1], ['weight_kg' => $weight]);
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        }
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_tiered_snapshot_does_not_change_when_case_weight_species_tiers_or_method_change(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size',
            'tiers' => [['size' => 'medium', 'price' => 600]]]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.40]);
        $payload = $this->payload($service, changes: ['applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.applied_price', '600.00')->json('data.id');
        $snapshot = ClinicCaseService::find($id)->service_snapshot;
        $this->assertEquals(18.40, $snapshot['pet_weight_kg']);
        DB::table('clinic_vitals')->where('clinic_appointment_id', 1)->update(['weight_kg' => 3]);
        DB::table('pets')->where('pet_id', 1)->update(['species' => 'cat', 'weight' => 4, 'size' => 'small']);
        $service->update(['tiers' => [['size' => 'small', 'price' => 300]], 'pricing_method' => 'fixed', 'price' => 900, 'is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.charge.unit_price', '600.00');
        $this->assertSame($snapshot, ClinicCaseService::find($id)->service_snapshot);
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_active_catalogue_lookup_and_server_pricing_preview_preserve_clinical_permissions(): void
    {
        $fixed = $this->catalog();
        $this->catalog(['is_active' => false]);
        $this->getJson('/api/admin/clinic-service-options?status=all')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-services')->assertForbidden();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$fixed->id)->assertOk()
            ->assertJsonPath('pricing.requires_final_price', false)->assertJsonPath('pricing.resolved_price', '500.00');
        $tiered = $this->catalog(['pricing_method' => 'tiered', 'tier_basis' => 'pet_size', 'tiers' => [['size' => 'medium', 'price' => 600]]]);
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$tiered->id)->assertOk()
            ->assertJsonPath('pricing.error', "Record the pet's weight in Vitals before performing this service.");
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.4]);
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$tiered->id)->assertOk()
            ->assertJsonPath('pricing.pet_size', 'medium')->assertJsonPath('pricing.resolved_price', '600.00');
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-service-options')->assertForbidden();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$fixed->id)->assertForbidden();
    }

    public function test_receptionist_can_view_services_but_cannot_mutate_them_and_vet_cannot_pay(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 500, 'payment_method' => 'cash'])->assertForbidden();
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertForbidden();
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertForbidden();
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

    public function test_inactive_services_request_key_collisions_and_cross_case_removals_are_rejected(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        ClinicAppointment::create(['status' => 'checked_in', 'pet_id' => 1]);
        $this->postJson('/api/admin/clinic-cases/2/services', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['clinic_service_id' => $this->catalog()->id]))->assertUnprocessable();
        $this->deleteJson('/api/admin/clinic-cases/2/services/'.$id)->assertNotFound();
        $this->patchJson('/api/admin/clinic-cases/2/charges/1', ['unit_price' => 0])->assertNotFound();
        $service->update(['is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 1);
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
            ['DELETE', 'api/admin/clinic-cases/{id}/services/{caseServiceId}', 'clinic:clinical'],
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
