<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Service;
use App\Models\User;
use App\Services\GroomingServicePriceResolver;
use App\Services\GroomingTimeEstimate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingManagedCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug')->unique();
            $table->string('service_name');
            $table->text('description')->nullable();
            foreach (['base_price', 'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max'] as $field) {
                $table->decimal($field, 8, 2)->nullable();
            }
            $table->boolean('is_starting_price')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('duration_minutes')->default(30);
            $table->json('starting_price_sizes')->nullable();
            $table->json('range_price_maximums')->nullable();
        });
        $this->seed(\Database\Seeders\ServiceSeeder::class);
        (require base_path('database/migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        Sanctum::actingAs(new User(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('services');
        parent::tearDown();
    }

    private function packagePayload(int $included): array
    {
        return ['name' => 'Coat Care Grooming', 'kind' => 'package', 'petType' => 'dog',
            'descriptionItems' => ['Gentle bath and coat conditioning', 'Careful brushing'],
            'includedServiceIds' => [$included], 'isActive' => true, 'displayOrder' => 2,
            'sizes' => ['small' => ['pricing_type' => 'fixed', 'amount' => '600'],
                'medium' => ['pricing_type' => 'fixed', 'amount' => '700'],
                'large' => ['pricing_type' => 'starting_at', 'amount' => '900'],
                'extra_large' => ['pricing_type' => 'starting_at', 'amount' => '1100']]];
    }

    public function test_managed_catalog_creates_and_updates_complete_booking_metadata(): void
    {
        $ear = Service::where('slug', 'ear_cleaning')->first();
        $payload = $this->packagePayload($ear->service_id);
        $created = $this->postJson('/api/admin/grooming/services', $payload)->assertCreated()
            ->assertJsonPath('data.name', 'Coat Care Grooming')->assertJsonPath('data.descriptionItems.1', 'Careful brushing')
            ->assertJsonPath('data.includedAlaCarteServiceIds', ['ear_cleaning'])
            ->assertJsonPath('data.priceOptions.2.pricingType', 'starting_at')->json('data');
        $service = Service::findOrFail($created['serviceId']);
        $this->assertSame('900.00', app(GroomingServicePriceResolver::class)->servicePrice($service, 'large'));
        $payload['name'] = 'Coat and Skin Care';
        $payload['descriptionItems'] = ['Nail Clipping is available separately'];
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertOk()
            ->assertJsonPath('data.id', $created['id'])->assertJsonPath('data.includedAlaCarteServiceIds', ['ear_cleaning']);
        $catalog = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Coat and Skin Care', $catalog[$created['id']]['name']);
        $this->assertSame(['Nail Clipping is available separately'], $catalog[$created['id']]['descriptionItems']);
        $estimate = app(GroomingTimeEstimate::class)->calculate($created['id'], null, 'small');
        $this->assertGreaterThan(0, $estimate['minMinutes']);
    }

    public function test_managed_catalog_deactivation_preserves_service_and_historical_prices(): void
    {
        $service = Service::where('slug', 'ear_cleaning')->first();
        $line = new BookingService(['price_at_booking' => 150, 'price_min_at_booking' => 150]);
        $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'small']));
        $this->patchJson('/api/admin/grooming/services/'.$service->service_id.'/status', ['isActive' => false])->assertOk();
        $catalog = $this->getJson('/api/grooming/services')->assertOk()->json('data');
        $this->assertNotContains('ear_cleaning', array_column($catalog, 'id'));
        $this->getJson('/api/admin/grooming/services')->assertOk()->assertJsonCount(10, 'data');
        $line->setRelation('service', $service->fresh());
        $rules = app(GroomingServicePriceResolver::class)->paymentPriceRules($line, 'small');
        $this->assertEquals(150, $rules['min']);
        $this->assertSame('plus', $rules['pricing_type']);
        $this->patchJson('/api/admin/grooming/services/'.$service->service_id.'/status', ['isActive' => true])->assertOk();
        $this->getJson('/api/grooming/services')->assertOk()->assertJsonCount(10, 'data');
    }

    public function test_managed_catalog_rejects_invalid_inclusions_and_prices_without_partial_updates(): void
    {
        $service = Service::where('slug', 'partial_grooming')->first();
        $payload = $this->packagePayload($service->service_id);
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('includedServiceIds.0');
        $payload['includedServiceIds'] = [];
        $payload['sizes']['medium']['amount'] = '0';
        $before = $service->getAttributes();
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('sizes.medium.amount');
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public function test_managed_catalog_new_ala_carte_is_available_for_pricing_and_estimates(): void
    {
        $created = $this->postJson('/api/admin/grooming/services', ['name' => 'Paw Balm Care', 'kind' => 'ala_carte',
            'amount' => '180', 'pricing_type' => 'fixed', 'isActive' => true, 'displayOrder' => 9])->assertCreated()->json('data');
        $this->assertSame(['cat', 'dog'], $created['petTypes']);
        $this->assertSame('180.00', app(GroomingServicePriceResolver::class)->servicePrice(Service::find($created['serviceId']), 'small'));
        $estimate = app(GroomingTimeEstimate::class)->calculate(null, null, 'small', [$created['id']]);
        $this->assertGreaterThan(0, $estimate['minMinutes']);
    }

    public function test_managed_catalog_only_admin_can_manage_services(): void
    {
        foreach (['staff', 'customer'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $this->postJson('/api/admin/grooming/services', [])->assertForbidden();
            $this->getJson('/api/admin/grooming/services')->assertForbidden();
            $this->putJson('/api/admin/grooming/services/1', [])->assertForbidden();
            $this->patchJson('/api/admin/grooming/services/1/status', ['isActive' => false])->assertForbidden();
        }
    }

    public function test_managed_catalog_preserves_estimates_when_size_is_not_yet_known(): void
    {
        $estimate = app(GroomingTimeEstimate::class)->calculate('partial_grooming', null, null);
        $this->assertSame([45, 105], [$estimate['minMinutes'], $estimate['maxMinutes']]);
    }

    public function test_inactive_services_remain_readable_for_staff_billing_only(): void
    {
        Service::where('slug', 'regular_dog_grooming')->update(['is_active' => false]);
        foreach (['admin', 'staff', 'customer'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $response = $this->getJson('/api/grooming/services?include_inactive=1')->assertOk();
            $this->assertSame($role !== 'customer', in_array('regular_dog_grooming', array_column($response->json('data'), 'id')));
            $this->getJson('/api/grooming/services')->assertOk()->assertJsonCount(9, 'data');
        }
    }

    public function test_newly_enabled_package_sizes_are_available_to_booking_estimates(): void
    {
        $service = Service::where('slug', 'cat_full_grooming')->firstOrFail();
        $payload = $this->packagePayload(Service::where('slug', 'ear_cleaning')->value('service_id'));
        $payload['petType'] = 'cat';
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertOk()
            ->assertJsonCount(4, 'data.priceOptions');
        $estimate = app(GroomingTimeEstimate::class)->calculate($service->slug, null, 'large');
        $this->assertGreaterThan(0, $estimate['minMinutes']);
        $this->assertSame(90, app(GroomingTimeEstimate::class)->calculate($service->slug, null, 'small')['minMinutes']);
    }
}
