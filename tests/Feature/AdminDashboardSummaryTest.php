<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\Pet;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->timestamps();
        });

        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
        ]);

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
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount', 8, 2)->default(0);
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->date('pet_queue_date')->nullable();
            $table->unsignedInteger('pet_queue_number')->nullable();
            $table->text('special_instructions')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->unique(['pet_queue_date', 'pet_queue_number']);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->text('medical_conditions')->nullable();
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
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('clinic_settings');

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
            $this->assertSame(
                range(1, count($species)),
                collect($formatted['pets'])->pluck('petQueueNumber')->all(),
            );
        }
    }

    public function test_pet_queue_continues_from_highest_number_on_effective_date_and_resets_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 08:00:00'));

        DB::table('bookings')->insert([
            [
                'booking_id' => 1,
                'booking_reference' => 'DONE-7',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 7,
                'status' => 'archived',
                'queue_number' => 1,
                'dropped_off_at' => '2026-06-29 07:00:00',
            ],
            [
                'booking_id' => 2,
                'booking_reference' => 'RELEASED-5',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 5,
                'status' => 'released',
                'queue_number' => 2,
                'dropped_off_at' => '2026-06-29 07:30:00',
            ],
            [
                'booking_id' => 3,
                'booking_reference' => 'CURRENT-5',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 5,
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ],
            [
                'booking_id' => 4,
                'booking_reference' => 'NEXT-DAY-1',
                'booking_date' => '2026-06-30',
                'number_of_pets' => 1,
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ],
        ]);

        $pets = [];
        $bookingPets = [];
        for ($petId = 1; $petId <= 18; $petId++) {
            $pets[] = [
                'pet_id' => $petId,
                'pet_name' => "Pet {$petId}",
                'species' => 'dog',
            ];

            $bookingId = $petId <= 7 ? 1 : ($petId <= 12 ? 2 : ($petId <= 17 ? 3 : 4));
            $bookingPets[] = [
                'booking_pet_id' => $petId,
                'booking_id' => $bookingId,
                'pet_id' => $petId,
                'pet_queue_date' => $petId <= 12 ? '2026-06-29' : null,
                'pet_queue_number' => $petId <= 12 ? $petId : null,
            ];
        }

        DB::table('pets')->insert($pets);
        DB::table('booking_pets')->insert($bookingPets);

        $controller = new AdminBookingController;
        $response = $controller->checkIn(3);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [13, 14, 15, 16, 17],
            DB::table('booking_pets')
                ->where('booking_id', 3)
                ->orderBy('booking_pet_id')
                ->pluck('pet_queue_number')
                ->all(),
        );
        $this->assertSame(
            ['2026-06-29'],
            DB::table('booking_pets')
                ->where('booking_id', 3)
                ->distinct()
                ->pluck('pet_queue_date')
                ->map(fn ($date) => substr($date, 0, 10))
                ->all(),
        );

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame(
            [13, 14, 15, 16, 17],
            collect($schedule['queuedList'][0]['pets'])->pluck('petQueueNumber')->all(),
        );

        Carbon::setTestNow(Carbon::parse('2026-06-30 08:00:00'));
        $nextDayResponse = $controller->checkIn(4);

        $this->assertSame(200, $nextDayResponse->getStatusCode());
        $this->assertSame(
            1,
            DB::table('booking_pets')->where('booking_id', 4)->value('pet_queue_number'),
        );
    }

    public function test_starting_pets_keeps_booking_queued_until_every_pet_has_started(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-START-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'pet_id' => 1],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 2],
        ]);

        $controller = new AdminBookingController;
        $firstResponse = $controller->startPetGrooming(1, 1);

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertFalse($firstResponse->getData(true)['all_pets_started']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_start_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame([1], collect($schedule['queuedList'])->pluck('id')->all());
        $this->assertSame([1], collect($schedule['inProgressList'])->pluck('id')->all());
        $this->assertSame('in-progress', $schedule['inProgressList'][0]['status']);
        $this->assertTrue($schedule['inProgressList'][0]['pets'][0]['isGroomingStarted']);
        $this->assertFalse($schedule['inProgressList'][0]['pets'][1]['isGroomingStarted']);

        $secondResponse = $controller->startPetGrooming(1, 2);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertTrue($secondResponse->getData(true)['all_pets_started']);
        $this->assertSame('in_progress', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 1)->value('grooming_started_at'));
    }

    #[DataProvider('groomerCapacities')]
    public function test_groomer_capacity_limits_active_pets_and_finishing_frees_a_slot(int $groomersOnDuty): void
    {
        DB::table('clinic_settings')->where('id', 1)->update([
            'groomers_on_duty' => $groomersOnDuty,
        ]);

        for ($index = 1; $index <= $groomersOnDuty + 1; $index++) {
            DB::table('bookings')->insert([
                'booking_id' => $index,
                'booking_reference' => "CAPACITY-{$groomersOnDuty}-{$index}",
                'booking_date' => '2026-06-24',
                'number_of_pets' => 1,
                'status' => 'checked_in',
                'queue_number' => $index,
            ]);
            DB::table('pets')->insert([
                'pet_id' => $index,
                'pet_name' => "Pet {$index}",
                'species' => 'dog',
            ]);
            DB::table('booking_pets')->insert([
                'booking_pet_id' => $index,
                'booking_id' => $index,
                'pet_id' => $index,
            ]);
        }

        $controller = new AdminBookingController;
        for ($index = 1; $index <= $groomersOnDuty; $index++) {
            $this->assertSame(200, $controller->startPetGrooming($index, $index)->getStatusCode());
        }

        $blockedPetId = $groomersOnDuty + 1;
        $blockedResponse = $controller->startPetGrooming($blockedPetId, $blockedPetId);
        $this->assertSame(422, $blockedResponse->getStatusCode());
        $this->assertStringContainsString('Groomer capacity is full', $blockedResponse->getData(true)['message']);

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame($groomersOnDuty, $schedule['groomerCapacity']['active_pets']);
        $this->assertTrue($schedule['groomerCapacity']['is_full']);

        $this->assertSame(200, $controller->markPetDone(1, 1)->getStatusCode());
        $this->assertSame(200, $controller->startPetGrooming($blockedPetId, $blockedPetId)->getStatusCode());
    }

    public static function groomerCapacities(): array
    {
        return [
            'one groomer' => [1],
            'two groomers' => [2],
            'three groomers' => [3],
        ];
    }

    public function test_groomers_on_duty_setting_can_be_updated(): void
    {
        $response = (new ClinicSettingController)->updateGroomersOnDuty(new Request([
            'groomers_on_duty' => 3,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $response->getData(true)['groomers_on_duty']);
        $this->assertSame(3, DB::table('clinic_settings')->where('id', 1)->value('groomers_on_duty'));
    }

    public function test_booking_level_start_cannot_bypass_pet_capacity(): void
    {
        DB::table('clinic_settings')->where('id', 1)->update(['groomers_on_duty' => 1]);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'CAPACITY-WHOLE-BOOKING',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'pet_id' => 1],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 2],
        ]);

        $response = (new AdminBookingController)->startGrooming(1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_start_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
    }

    public function test_finishing_pets_keeps_booking_in_progress_until_every_pet_is_done(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-FINISH-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'in_progress',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => '2026-06-24 10:05:00',
            ],
        ]);

        $controller = new AdminBookingController;
        $firstResponse = $controller->markPetDone(1, 1);

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertFalse($firstResponse->getData(true)['all_pets_finished']);
        $this->assertSame('in_progress', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_end_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_end_time'));
        $this->assertSame(0, DB::table('notifications')->count());

        $secondResponse = $controller->markPetDone(1, 2);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertTrue($secondResponse->getData(true)['all_pets_finished']);
        $this->assertSame('for_payment', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 1)->value('grooming_finished_at'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_end_time'));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_started_pet_can_finish_while_sibling_remains_queued(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-PARTIAL-FINISH-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => null,
            ],
        ]);

        $response = (new AdminBookingController)->markPetDone(1, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['all_pets_finished']);
        $this->assertSame('checked_in', $response->getData(true)['booking_status']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_end_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));

        $schedule = (new AdminBookingController)->index(
            Request::create('/api/admin/bookings', 'GET'),
        )->getData(true);

        $this->assertSame([1], collect($schedule['inProgressList'])->pluck('id')->all());
        $this->assertTrue($schedule['inProgressList'][0]['pets'][0]['isGroomingFinished']);
        $this->assertFalse($schedule['inProgressList'][0]['pets'][1]['isGroomingStarted']);
    }

    public function test_early_payment_waits_for_every_pet_before_moving_to_pickup(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-EARLY-PAY-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_end_time' => null,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
            ],
        ]);

        $paymentResponse = (new PaymentController)->payNow(new Request([
            'final_price' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
        ]), 1);

        $this->assertSame(200, $paymentResponse->getStatusCode());
        $this->assertFalse($paymentResponse->getData(true)['all_pets_finished']);
        $this->assertSame(2, $paymentResponse->getData(true)['remaining_pets']);
        $this->assertSame('checked_in', $paymentResponse->getData(true)['booking_status']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertTrue((bool) DB::table('bookings')->where('booking_id', 1)->value('paid'));

        $bookingController = new AdminBookingController;
        $firstFinish = $bookingController->markPetDone(1, 1);

        $this->assertSame(200, $firstFinish->getStatusCode());
        $this->assertFalse($firstFinish->getData(true)['all_pets_finished']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));

        $bookingController->startPetGrooming(1, 2);
        $finalFinish = $bookingController->markPetDone(1, 2);

        $this->assertSame(200, $finalFinish->getStatusCode());
        $this->assertTrue($finalFinish->getData(true)['all_pets_finished']);
        $this->assertSame('released', $finalFinish->getData(true)['booking_status']);
        $this->assertSame('released', DB::table('bookings')->where('booking_id', 1)->value('status'));
    }
}
