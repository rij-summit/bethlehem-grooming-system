<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicInventoryPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
            $table->string('first_name')->nullable(); $table->string('last_name')->nullable();
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id'); $table->string('pet_name'); $table->decimal('weight', 8, 2)->nullable();
        });
        Schema::create('walkins', fn (Blueprint $table) => $table->id());
        foreach (['2026_07_05_100000_create_inventory_items_table', '2026_07_04_211409_create_clinic_appointments_table',
            '2026_07_04_211409_create_clinic_records_table', '2026_07_04_211409_create_clinic_vitals_table',
            '2026_07_04_211410_create_clinic_attachments_table', '2026_10_10_000001_create_clinic_charges_table',
            '2026_10_10_000002_add_clinic_inventory_pricing', '2026_10_11_000001_add_inventory_weight_pricing_mode'] as $file) {
            (require database_path("migrations/{$file}.php"))->up();
        }
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->increments('transaction_id'); $table->unsignedInteger('item_id'); $table->string('type');
            $table->decimal('quantity', 10, 2); $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable(); $table->string('reason');
            $table->string('batch_number')->nullable(); $table->date('expiry_date')->nullable();
            $table->string('reference_type')->default('manual'); $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable(); $table->unsignedInteger('performed_by')->nullable(); $table->timestamp('created_at')->nullable();
        });
        Sanctum::actingAs(User::create(['role' => 'admin']));
    }

    private function product(array $changes = []): array
    {
        return array_replace([
            'item_name' => 'Clinic medicine', 'category' => 'medicine', 'unit' => 'bottle',
            'dose_unit' => 'mL', 'medicine_type' => 'deworming', 'pricing_method' => 'weight_based',
            'strength_concentration' => '10 mg/mL', 'unit_cost' => 50, 'selling_price' => null, 'reorder_level' => 0,
            'weight_prices' => [
                ['min_weight_kg' => 0, 'max_weight_kg' => 5.1, 'price' => 200],
                ['min_weight_kg' => 5.1, 'max_weight_kg' => 10.1, 'price' => 400],
                ['min_weight_kg' => 10.1, 'max_weight_kg' => 15.1, 'price' => 700],
                ['min_weight_kg' => 15.1, 'max_weight_kg' => null, 'price' => 900],
            ],
        ], $changes);
    }

    private function item(array $changes = []): InventoryItem
    {
        $id = $this->postJson('/api/inventory/items', $this->product($changes))->assertCreated()->json('data.item_id');
        return InventoryItem::findOrFail($id);
    }

    private function appointment(?float $weight = 12.4): ClinicAppointment
    {
        $pet = Pet::create(['pet_name' => 'Patient', 'weight' => $weight]);
        return ClinicAppointment::create(['appointment_reference' => 'TEST-'.Str::random(8),
            'appointment_date' => today(), 'pet_id' => $pet->pet_id, 'status' => 'in_consultation']);
    }

    private function charge(InventoryItem $item, float $price = 700, ?float $weight = 12.4, ?float $quantity = null): array
    {
        return ['inventory_item_id' => $item->item_id, 'expected_price' => $price,
            'expected_weight_kg' => $weight, 'inventory_quantity' => $quantity, 'request_key' => (string) Str::uuid()];
    }

    public function test_threshold_examples_round_trip_on_add_and_edit_without_changing_cost_or_stock(): void
    {
        $examples = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true)['pricing'];
        foreach ($examples as $example) {
            $ranges = array_map(fn ($tier) => ['min_weight_kg' => $tier[0], 'max_weight_kg' => $tier[1], 'price' => $tier[2]], $example['tiers']);
            $item = $this->item(['weight_prices' => $ranges, 'unit_cost' => 73.25]);
            $this->assertSame('threshold', $item->weight_pricing_mode);
            $this->assertSame('73.25', $item->unit_cost);
            $this->assertNull($item->selling_price);
            foreach ($example['examples'] as [$weight, $price]) {
                $range = \App\Support\ProductWeightPricing::rangeFor($item->weightPrices->toArray(), $weight);
                $this->assertEquals($price, $range['price'] ?? null);
                if ($price !== null) $this->assertEquals($price, $item->clinicPrice($weight)['price']);
            }
            $this->postJson('/api/inventory/stock-in', ['items' => [[
                'item_id' => $item->item_id, 'quantity' => 3, 'reason' => 'purchase',
                'batch_number' => 'TIERS', 'expiry_date' => today()->addYear()->toDateString(),
            ]]])->assertCreated();
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $ranges, 'unit_cost' => 73.25]))
                ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'threshold')->assertJsonPath('data.unit_cost', '73.25');
            $this->assertSame('3.00', $item->fresh()->quantity_on_hand);
            $bad = $ranges; $bad[1]['min_weight_kg'] = 0;
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $bad]))->assertUnprocessable();
            $this->assertSame('73.25', $item->fresh()->unit_cost);
        }
    }

    public function test_decimal_thresholds_and_invalid_order_are_validated_by_the_server(): void
    {
        $ranges = [['min_weight_kg' => 0, 'max_weight_kg' => 2.25, 'price' => 100],
            ['min_weight_kg' => 2.25, 'max_weight_kg' => 4.5, 'price' => 200],
            ['min_weight_kg' => 4.5, 'max_weight_kg' => null, 'price' => 300]];
        $item = $this->item(['weight_prices' => $ranges]);
        foreach ([[2.249, 100], [2.25, 200], [4.499, 200], [4.5, 300]] as [$weight, $price]) {
            $this->assertEquals($price, $item->clinicPrice($weight)['price']);
        }
        $descending = [$ranges[0], $ranges[2], $ranges[1]];
        $this->postJson('/api/inventory/items', $this->product(['weight_prices' => $descending]))->assertUnprocessable();
        $this->postJson('/api/inventory/items', $this->product(['weight_prices' => [['min_weight_kg' => 0, 'max_weight_kg' => 0, 'price' => 10]]]))->assertUnprocessable();
    }

    public function test_legacy_ranges_and_prices_survive_migration_and_unrelated_edits(): void
    {
        $item = $this->item();
        $legacy = [['min_weight_kg' => 0, 'max_weight_kg' => 2, 'price' => 250],
            ['min_weight_kg' => 2.1, 'max_weight_kg' => 4, 'price' => 350]];
        $item->weightPrices()->delete(); $item->weightPrices()->createMany($legacy);
        $item->update(['weight_pricing_mode' => 'legacy', 'quantity_on_hand' => 7]);
        $migration = require database_path('migrations/2026_10_11_000001_add_inventory_weight_pricing_mode.php');
        $migration->down(); $migration->up();
        $item = $item->fresh();
        $this->assertSame('legacy', $item->weight_pricing_mode);
        $this->assertEquals(250, $item->clinicPrice(2)['price']);
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $legacy]))
            ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'legacy');
        $this->assertSame('7.00', $item->fresh()->quantity_on_hand);
        $changed = $legacy; $changed[0]['max_weight_kg'] = 3;
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $changed]))->assertUnprocessable();
        $refined = [['min_weight_kg' => 0, 'max_weight_kg' => 2.1, 'price' => 250], $legacy[1]];
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $refined, 'weight_pricing_mode' => 'threshold']))
            ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'threshold');
        $this->assertEquals(350, $item->fresh()->clinicPrice(2.1)['price']);
    }

    public function test_medicine_types_and_ordered_relational_ranges_round_trip(): void
    {
        foreach (InventoryItem::MEDICINE_TYPES as $type) {
            $item = $this->item(['medicine_type' => $type]);
            $this->assertNull($item->selling_price);
            $this->assertSame($type, $item->medicine_type);
            $this->assertEquals([0, 5.1, 10.1, 15.1], $item->weightPrices->pluck('min_weight_kg')->map(fn ($n) => (float) $n)->all());
            $this->getJson('/api/inventory/items/'.$item->item_id)->assertOk()->assertJsonCount(4, 'data.weight_prices');
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['medicine_type' => $type]))->assertOk();
            $this->assertSame(4, $item->weightPrices()->count());
        }
    }

    public function test_required_fields_and_all_invalid_range_configurations_are_rejected(): void
    {
        foreach (['medicine_type', 'dose_unit', 'pricing_method'] as $field) {
            $payload = $this->product(); unset($payload[$field]);
            $this->postJson('/api/inventory/items', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $r = fn ($min, $max, $price = 200) => ['min_weight_kg' => $min, 'max_weight_kg' => $max, 'price' => $price];
        foreach ([[], [$r(-1, 5)], [$r(5, 5)], [$r(5, 4)], [$r(0, 5, 0)], [$r(0, 5, -1)],
            [$r(0, 5, 'NaN')], [$r(0, 5, 2.345)], [$r(0, 5), $r(4, 10)], [$r(0, 5), $r(0, 5)],
            [$r(0, null), $r(5, 10)], [$r(0.001, 5)]] as $ranges) {
            $this->postJson('/api/inventory/items', $this->product(['weight_prices' => $ranges]))->assertUnprocessable();
        }
        $this->postJson('/api/inventory/items', $this->product(['selling_price' => 100]))->assertUnprocessable()->assertJsonValidationErrors('selling_price');
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_item_weight_prices', 0);
    }

    public function test_vaccines_flat_medicines_and_retail_keep_flat_prices(): void
    {
        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $id = $this->postJson('/api/inventory/items', $vaccine)->assertCreated()->assertJsonPath('data.pricing_method', 'flat')->json('data.item_id');
        $this->putJson('/api/inventory/items/'.$id, [...$vaccine, 'selling_price' => 975])->assertOk()->assertJsonPath('data.selling_price', '975.00');
        $this->postJson('/api/inventory/items', [...$vaccine, 'dose_unit' => null])->assertUnprocessable();
        $this->postJson('/api/inventory/items', [...$vaccine, 'medicine_type' => 'deworming'])->assertUnprocessable();
        $this->postJson('/api/inventory/items', [...$vaccine, 'pricing_method' => 'weight_based'])->assertUnprocessable();
        $this->postJson('/api/inventory/items', [...$vaccine, 'weight_prices' => $this->product()['weight_prices']])->assertUnprocessable();
        $flat = $this->item(['pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 300]);
        $this->assertSame('300.00', $flat->clinicPrice(null)['price']);
        foreach (InventoryItem::RETAIL_CATEGORIES as $category) {
            $payload = ['item_name' => $category, 'category' => $category, 'unit' => 'box', 'unit_cost' => 20, 'selling_price' => 30, 'reorder_level' => 0];
            $this->postJson('/api/inventory/items', $payload)->assertCreated()->assertJsonPath('data.dose_unit', null)->assertJsonPath('data.selling_price', '30.00');
        }
        $this->getJson('/api/inventory/search?q=Clinic&sale_context=pos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_strength_is_optional_and_saved_only_for_medicine(): void
    {
        $medicine = $this->item();
        $this->assertSame('10 mg/mL', $medicine->strength_concentration);
        $this->putJson('/api/inventory/items/'.$medicine->item_id, $this->product(['strength_concentration' => '50 mg/tablet']))
            ->assertOk()->assertJsonPath('data.strength_concentration', '50 mg/tablet');
        $this->assertSame('50 mg/tablet', $medicine->fresh()->strength_concentration);
        $this->postJson('/api/inventory/items', $this->product(['strength_concentration' => str_repeat('x', 151)]))
            ->assertUnprocessable()->assertJsonValidationErrors('strength_concentration');
        $optional = $this->product(); unset($optional['strength_concentration']);
        $this->postJson('/api/inventory/items', $optional)->assertCreated()->assertJsonPath('data.strength_concentration', null);

        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat',
            'weight_prices' => [], 'selling_price' => 950, 'strength_concentration' => '>4.6 log10 PFU']);
        $id = $this->postJson('/api/inventory/items', $vaccine)->assertCreated()
            ->assertJsonPath('data.strength_concentration', null)->json('data.item_id');
        DB::table('inventory_items')->where('item_id', $id)->update(['strength_concentration' => '>4.6 log10 PFU']);
        unset($vaccine['strength_concentration']);
        $this->putJson('/api/inventory/items/'.$id, $vaccine)->assertOk()->assertJsonPath('data.strength_concentration', null);
        $this->assertNull(InventoryItem::findOrFail($id)->strength_concentration);

        foreach (InventoryItem::RETAIL_CATEGORIES as $category) {
            $payload = ['item_name' => $category, 'category' => $category, 'unit' => 'box', 'unit_cost' => 20,
                'selling_price' => 30, 'reorder_level' => 0, 'strength_concentration' => '100 mg'];
            $id = $this->postJson('/api/inventory/items', $payload)->assertCreated()
                ->assertJsonPath('data.strength_concentration', null)->json('data.item_id');
            $this->assertNull(InventoryItem::findOrFail($id)->strength_concentration);
        }
        $this->putJson('/api/inventory/items/'.$medicine->item_id, $vaccine)->assertOk()
            ->assertJsonPath('data.strength_concentration', null);
        $this->assertNull($medicine->fresh()->strength_concentration);
    }

    public function test_category_and_pricing_changes_clean_ranges_only_when_saved(): void
    {
        $item = $this->item();
        $invalid = $this->product(['category' => 'vaccine', 'selling_price' => 950]);
        $this->putJson('/api/inventory/items/'.$item->item_id, $invalid)->assertUnprocessable();
        $this->assertSame(4, $item->weightPrices()->count());
        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $this->putJson('/api/inventory/items/'.$item->item_id, $vaccine)->assertOk()->assertJsonPath('data.medicine_type', null)->assertJsonCount(0, 'data.weight_prices');
        $this->assertSame(0, $item->weightPrices()->count());
        $other = $this->item();
        $this->postJson('/api/inventory/items/'.$other->item_id.'/deactivate')->assertOk();
        $this->assertSame(4, $other->weightPrices()->count());
        $this->postJson('/api/inventory/items/'.$other->item_id.'/reactivate')->assertOk()->assertJsonCount(4, 'data.weight_prices');
        $other->delete();
        $this->assertSame(0, DB::table('inventory_item_weight_prices')->where('item_id', $other->item_id)->count());
    }

    public function test_clinic_prices_decimal_boundaries_and_open_range_without_deriving_quantity(): void
    {
        $item = $this->item();
        foreach ([[0.1, 200], [5, 200], [5.1, 400], [10, 400], [10.1, 700], [12.4, 700], [15, 700], [41, 900]] as [$weight, $price]) {
            $appt = $this->appointment($weight);
            $entry = $this->charge($item, $price, $weight);
            $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$entry]])->assertOk();
            $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => $appt->id, 'amount' => $price, 'pet_weight_kg' => $weight, 'quantity' => 1]);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$entry]])->assertOk();
            $this->assertSame(1, $appt->charges()->count());
        }
    }

    public function test_missing_zero_and_unconfigured_weight_never_guess_a_price(): void
    {
        $item = $this->item();
        foreach ([null, 0] as $weight) {
            $appt = $this->appointment($weight);
            $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item)]])->assertUnprocessable();
            $this->assertSame(0, $appt->charges()->count());
        }
        $item->weightPrices()->where('min_weight_kg', 15.1)->update(['max_weight_kg' => 20]);
        $appt = $this->appointment(20.01);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item)]])
            ->assertUnprocessable()->assertJsonPath('errors.medicine.0', "No price is configured for this pet's weight. Correct the pricing configuration or pet weight.");
    }

    public function test_saved_vitals_take_precedence_and_stale_prices_are_rejected_atomically(): void
    {
        $item = $this->item(); $appt = $this->appointment(4);
        $entry = $this->charge($item);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['weight_kg' => 12.4, 'medicines' => [$entry]])->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => $appt->id, 'pet_weight_kg' => 12.4, 'amount' => 700]);
        $entry = $this->charge($item, 950);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['diagnosis' => 'must roll back', 'medicines' => [$entry]])->assertUnprocessable();
        $this->assertDatabaseMissing('clinic_records', ['diagnosis' => 'must roll back']);
        $this->assertSame(1, $appt->charges()->count());
        $vaccine = $this->item(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($vaccine, 950)]])->assertUnprocessable();
    }

    public function test_manual_stock_use_enforces_expiry_and_retries_do_not_deduct_twice(): void
    {
        $item = $this->item(); $appt = $this->appointment();
        $this->postJson('/api/inventory/stock-in', ['items' => [[
            'item_id' => $item->item_id, 'quantity' => 2, 'reason' => 'purchase', 'expiry_date' => today()->addYear()->toDateString(), 'batch_number' => 'M-1',
        ]]])->assertCreated();
        $entry = $this->charge($item, 700, 12.4, 0.25);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$entry]])->assertOk();
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$entry]])->assertOk();
        $this->assertSame('1.75', $item->fresh()->quantity_on_hand);
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('inventory_transactions', ['reference_type' => 'clinic', 'reference_id' => $appt->id, 'quantity' => 0.25, 'selling_price_at_time' => 700]);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item, 700, 12.4, 2)]])->assertUnprocessable();
        DB::table('inventory_transactions')->where('type', 'stock_in')->update(['expiry_date' => today()->subDay()]);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item, 700, 12.4, 0.25)]])->assertUnprocessable();
        $this->assertSame(1, $appt->charges()->count());
        $this->assertSame('1.75', $item->fresh()->quantity_on_hand);
    }

    public function test_options_only_include_active_medicines_and_clinical_permissions_apply(): void
    {
        $item = $this->item();
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(4, 'data.0.weight_prices');
        $item->update(['is_active' => false]);
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertOk()->assertJsonCount(0, 'data');
        $appt = $this->appointment(); $item->update(['is_active' => true]);
        $appt->update(['status' => 'completed']);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item)]])->assertStatus(409);
        Sanctum::actingAs(User::create(['role' => 'customer']));
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertForbidden();
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item)]])->assertForbidden();
    }

    public function test_migration_preserves_legacy_products_without_inventing_dose_units(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_clinic_inventory_pricing.php');
        $migration->down();
        foreach (['medicine', 'vaccine', 'food'] as $category) {
            DB::table('inventory_items')->insert(['item_name' => 'Legacy '.$category, 'category' => $category, 'unit' => 'bottle', 'unit_cost' => 20, 'selling_price' => 50]);
        }
        $migration->up();
        foreach (InventoryItem::all() as $item) {
            $this->assertSame('50.00', $item->selling_price);
            $this->assertSame('flat', $item->pricing_method);
            $this->assertNull($item->dose_unit);
            $this->assertSame($item->category === 'medicine' ? 'general_medication' : null, $item->medicine_type);
        }
        $legacy = InventoryItem::where('category', 'medicine')->first();
        $this->putJson('/api/inventory/items/'.$legacy->item_id, ['dose_unit' => 'mL', 'unit_cost' => 20, 'selling_price' => 50, 'reorder_level' => 0])->assertOk();
    }
}
