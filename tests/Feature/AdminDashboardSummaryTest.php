<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\Pet;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->integer('queue_number')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id')->nullable();
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('payment_status');
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->dateTime('paid_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');

        parent::tearDown();
    }

    public function test_dashboard_counts_pets_in_today_and_week_summaries(): void
    {
        DB::table('bookings')->insert([
            [
                'booking_reference' => 'COMPLETED-10',
                'booking_date' => '2026-06-24',
                'number_of_pets' => 10,
                'status' => 'archived',
                'grooming_finished_at' => '2026-06-24 10:00:00',
            ],
            [
                'booking_reference' => 'WEEKLY-3',
                'booking_date' => '2026-06-25',
                'number_of_pets' => 3,
                'status' => 'archived',
                'grooming_finished_at' => null,
            ],
            [
                'booking_reference' => 'NO-SHOW-4',
                'booking_date' => '2026-06-26',
                'number_of_pets' => 4,
                'status' => 'no_show',
                'grooming_finished_at' => null,
            ],
            [
                'booking_reference' => 'CANCELLED-7',
                'booking_date' => '2026-06-24',
                'number_of_pets' => 7,
                'status' => 'cancelled',
                'grooming_finished_at' => '2026-06-24 11:00:00',
            ],
            [
                'booking_reference' => 'OUTSIDE-WEEK-9',
                'booking_date' => '2026-06-21',
                'number_of_pets' => 9,
                'status' => 'archived',
                'grooming_finished_at' => null,
            ],
        ]);

        $response = (new AdminBookingController)->index(
            Request::create('/api/admin/bookings', 'GET'),
        );
        $summary = $response->getData(true)['summary'];

        $this->assertSame(10, $summary['today']);
        $this->assertSame(17, $summary['week']);
        $this->assertSame(1, $summary['noShowWeek']);
        $this->assertSame(33.3, $summary['noShowWeekRate']);
    }

    public function test_schedule_pet_type_summary_uses_all_pets_with_correct_pluralization(): void
    {
        $cases = [
            [['dog', 'cat'], 'Dog and Cat'],
            [['dog', 'dog', 'cat'], 'Dogs and Cat'],
            [['dog', 'cat', 'cat'], 'Dog and Cats'],
            [['dog', 'dog', 'cat', 'cat'], 'Dogs and Cats'],
        ];

        $formatBooking = new \ReflectionMethod(AdminBookingController::class, 'formatBooking');
        $controller = new AdminBookingController;

        foreach ($cases as [$species, $expected]) {
            $booking = new Booking([
                'booking_reference' => 'PET-TYPES',
                'booking_date' => '2026-06-24',
                'number_of_pets' => count($species),
                'status' => 'waiting_to_arrive',
            ]);
            $booking->setAttribute('booking_id', 1);
            $booking->setRelation('user', null);
            $booking->setRelation('timeWindow', null);
            $booking->setRelation('bookingServices', collect());
            $booking->setRelation('payments', collect());
            $booking->setRelation('bookingPets', collect($species)->map(function ($type, $index) {
                $pet = new Pet([
                    'pet_name' => 'Pet ' . ($index + 1),
                    'species' => $type,
                ]);
                $bookingPet = new BookingPet;
                $bookingPet->setAttribute('booking_pet_id', $index + 1);
                $bookingPet->setRelation('pet', $pet);

                return $bookingPet;
            }));

            $formatted = $formatBooking->invoke($controller, $booking);

            $this->assertSame($expected, $formatted['petType']);
        }
    }
}
