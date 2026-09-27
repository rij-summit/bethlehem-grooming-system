<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\BookingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GroomingPriceBoundsTest extends TestCase
{
    public function test_migration_adds_and_removes_booking_price_snapshots(): void
    {
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
        });
        $migration = require base_path('database/migrations/2026_09_27_000001_add_price_bounds_to_booking_services.php');

        try {
            $migration->up();
            $this->assertTrue(Schema::hasColumn('booking_services', 'price_min_at_booking'));
            $this->assertTrue(Schema::hasColumn('booking_services', 'price_max_at_booking'));
            $migration->down();
            $this->assertFalse(Schema::hasColumn('booking_services', 'price_min_at_booking'));
            $this->assertFalse(Schema::hasColumn('booking_services', 'price_max_at_booking'));
        } finally {
            Schema::dropIfExists('booking_services');
        }
    }

    public function test_payment_rejects_out_of_range_prices_and_keeps_the_original_bounds(): void
    {
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
        });
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });
        $line = BookingService::create([
            'booking_id' => 1,
            'booking_pet_id' => 2,
            'price_at_booking' => 0,
            'price_min_at_booking' => 50,
            'price_max_at_booking' => 100,
        ]);
        $booking = new Booking;
        $booking->booking_id = 1;
        $method = new \ReflectionMethod(PaymentController::class, 'updateBookingServicePrices');

        try {
            foreach ([49, 101] as $invalid) {
                try {
                    $method->invoke(new PaymentController, $booking, [
                        ['booking_service_id' => $line->booking_service_id, 'amount' => $invalid],
                    ], [2]);
                    $this->fail('An out-of-range price was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertSame('Enter an amount from ₱50 to ₱100.', $exception->errors()['service_prices'][0]);
                }
                $this->assertEquals(0, $line->fresh()->price_at_booking);
            }

            foreach ([50, 75, 100] as $valid) {
                $method->invoke(new PaymentController, $booking, [
                    ['booking_service_id' => $line->booking_service_id, 'amount' => $valid],
                ], [2]);
                $this->assertEquals($valid, $line->fresh()->price_at_booking);
                $this->assertEquals(50, $line->fresh()->price_min_at_booking);
                $this->assertEquals(100, $line->fresh()->price_max_at_booking);
            }
        } finally {
            Schema::dropIfExists('booking_services');
            Schema::dropIfExists('booking_pets');
        }
    }

    public function test_minimum_only_service_rejects_below_minimum_without_changing_the_entered_price(): void
    {
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
        });
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });
        $line = BookingService::create([
            'booking_id' => 1,
            'booking_pet_id' => 2,
            'price_at_booking' => 400,
            'price_min_at_booking' => 400,
        ]);
        $booking = new Booking;
        $booking->booking_id = 1;
        $method = new \ReflectionMethod(PaymentController::class, 'updateBookingServicePrices');

        try {
            try {
                $method->invoke(new PaymentController, $booking, [
                    ['booking_service_id' => $line->booking_service_id, 'amount' => 399],
                ], [2]);
                $this->fail('A below-minimum price was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame('Enter an amount of at least ₱400.', $exception->errors()['service_prices'][0]);
            }
            $this->assertEquals(400, $line->fresh()->price_at_booking);

            foreach ([400, 450, 500] as $valid) {
                $method->invoke(new PaymentController, $booking, [
                    ['booking_service_id' => $line->booking_service_id, 'amount' => $valid],
                ], [2]);
                $this->assertEquals($valid, $line->fresh()->price_at_booking);
            }
        } finally {
            Schema::dropIfExists('booking_services');
            Schema::dropIfExists('booking_pets');
        }
    }

    public function test_changed_pet_size_uses_the_new_package_minimum_without_rewriting_the_booking_snapshot(): void
    {
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->string('confirmed_size')->nullable();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug');
        });
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });
        DB::table('booking_pets')->insert(['booking_pet_id' => 2, 'confirmed_size' => 'large']);
        DB::table('services')->insert(['service_id' => 3, 'slug' => 'partial_grooming']);
        $line = BookingService::create([
            'booking_id' => 1,
            'booking_pet_id' => 2,
            'service_id' => 3,
            'price_at_booking' => 600,
            'price_min_at_booking' => 600,
        ]);
        $booking = new Booking;
        $booking->booking_id = 1;
        $method = new \ReflectionMethod(PaymentController::class, 'updateBookingServicePrices');
        $sizes = [['booking_pet_id' => 2, 'size' => 'extra_large']];

        try {
            try {
                $method->invoke(new PaymentController, $booking, [
                    ['booking_service_id' => $line->booking_service_id, 'amount' => 650],
                ], [2], $sizes);
                $this->fail('A price below the selected size minimum was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame('Enter an amount of at least ₱700.', $exception->errors()['service_prices'][0]);
            }
            $this->assertEquals(600, $line->fresh()->price_at_booking);

            $method->invoke(new PaymentController, $booking, [
                ['booking_service_id' => $line->booking_service_id, 'amount' => 700],
            ], [2], $sizes);
            $this->assertEquals(700, $line->fresh()->price_at_booking);
            $this->assertEquals(600, $line->fresh()->price_min_at_booking);
        } finally {
            Schema::dropIfExists('booking_services');
            Schema::dropIfExists('services');
            Schema::dropIfExists('booking_pets');
        }
    }
}
