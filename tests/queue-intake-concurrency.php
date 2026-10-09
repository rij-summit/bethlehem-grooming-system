<?php

// Standalone MySQL regression: php tests/queue-intake-concurrency.php
// Uses a uniquely named disposable database; never modifies the application's database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClinicSetting;
use App\Services\DailyPetQueue;
use App\Services\OperationalCapacity;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

Carbon::setTestNow('2026-10-09 10:00:00');
$worker = ($argv[1] ?? '') === '--worker';
$database = $worker ? $argv[2] : 'bethlehem_queue_test_'.bin2hex(random_bytes(6));
if (! preg_match('/^bethlehem_queue_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Refusing to use a non-test database.');
}
$connection = config('database.connections.mysql');
$connection['url'] = null;
$connection['database'] = $worker ? $database : null;
config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
DB::purge('mysql');

if ($worker) {
    $service = $argv[3];
    $intakeId = (int) $argv[4];
    try {
        app(DailyPetQueue::class)->runForDate(now()->toDateString(), function () use ($service, $intakeId) {
            app(OperationalCapacity::class)->assertCanAccept($service, 1);
            // Keep the first intake open long enough for the other worker to contend for the lock.
            usleep(200000);
            if ($service === 'grooming') {
                DB::table('bookings')->where('booking_id', $intakeId)->update(['status' => 'checked_in', 'dropped_off_at' => now()]);
            } else {
                DB::table('clinic_appointments')->where('id', $intakeId)->update(['status' => 'checked_in', 'checked_in_at' => now()]);
            }
        });
        echo 'ACCEPTED';
    } catch (ValidationException) {
        echo 'FULL';
    }
    exit;
}

$created = false;
try {
    DB::statement("CREATE DATABASE `{$database}`");
    $created = true;
    config(['database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    Schema::create('clinic_settings', function (Blueprint $table) {
        $table->id();
        $table->unsignedTinyInteger('groomers_on_duty')->default(2);
        foreach (ClinicSetting::availabilityDefaults() as $column => $time) {
            $table->time($column)->default($time);
        }
        $table->timestamps();
    });
    ClinicSetting::current();
    Schema::create('bookings', function (Blueprint $table) {
        $table->increments('booking_id');
        $table->enum('status', ['waiting_to_arrive', 'no_show', 'checked_in', 'in_progress', 'completed', 'cancelled'])->default('waiting_to_arrive');
        $table->date('booking_date');
        $table->dateTime('dropped_off_at')->nullable();
    });
    Schema::create('booking_pets', function (Blueprint $table) {
        $table->increments('booking_pet_id');
        $table->unsignedInteger('booking_id');
        $table->string('grooming_state')->default('not_started');
        $table->dateTime('grooming_start_time')->nullable();
        $table->dateTime('grooming_end_time')->nullable();
    });
    Schema::create('clinic_appointments', function (Blueprint $table) {
        $table->id();
        $table->enum('status', ['waiting_to_arrive', 'no_show', 'checked_in', 'in_consultation', 'completed', 'cancelled'])->default('checked_in');
        $table->date('appointment_date');
        $table->dateTime('checked_in_at')->nullable();
    });
    Schema::create('customer_notifications', fn (Blueprint $table) => $table->id());
    DB::table('bookings')->insert(['booking_id' => 1, 'status' => 'no_show', 'booking_date' => '2026-10-08']);
    DB::table('clinic_appointments')->insert(['id' => 1, 'status' => 'no_show', 'appointment_date' => '2026-10-08']);
    $migration = require __DIR__.'/../database/migrations/2026_10_09_120000_add_expired_pre_registration_status.php';
    $migration->up();
    foreach (['bookings', 'clinic_appointments'] as $table) {
        $legacy = DB::table($table)->first();
        if ($legacy->status !== 'expired' || $legacy->legacy_pre_registration_status !== 'no_show') {
            throw new RuntimeException('MySQL migration did not preserve and normalize legacy history.');
        }
    }
    DB::table('bookings')->insert(['booking_id' => 2, 'status' => 'waiting_to_arrive', 'booking_date' => '2026-10-09']);
    DB::table('booking_pets')->insert(['booking_id' => 2]);
    DB::table('bookings')->insert(['booking_id' => 3, 'status' => 'waiting_to_arrive', 'booking_date' => '2026-10-09']);
    DB::table('booking_pets')->insert(['booking_id' => 3]);
    DB::table('bookings')->insert(['booking_id' => 4, 'status' => 'in_progress', 'booking_date' => '2026-10-09', 'dropped_off_at' => now()]);
    foreach (range(1, 19) as $pet) {
        DB::table('booking_pets')->insert(['booking_id' => 4, 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
    }
    foreach (range(2, 20) as $id) {
        DB::table('clinic_appointments')->insert(['id' => $id, 'status' => 'in_consultation', 'appointment_date' => '2026-10-09', 'checked_in_at' => now()]);
    }
    DB::table('clinic_appointments')->insert(['id' => 21, 'status' => 'waiting_to_arrive', 'appointment_date' => '2026-10-09']);
    $processes = [];
    foreach ([['grooming', 2], ['grooming', 3], ['clinic', 21]] as [$service, $intakeId]) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, $service, (string) $intakeId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('Could not start intake worker.');
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Intake worker failed: '.$errors);
    }
    sort($results);
    if ($results !== ['ACCEPTED', 'ACCEPTED', 'FULL'] || app(OperationalCapacity::class)->snapshot()['used'] !== 20
        || DB::table('clinic_appointments')->where('id', 21)->value('status') !== 'checked_in') {
        throw new RuntimeException('Concurrent intake exceeded capacity or was not correctly serialized.');
    }
    app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => app(OperationalCapacity::class)->assertCanAccept('clinic', 1));
    echo "MySQL status migration and simultaneous intake passed: one Grooming accepted, one full; Clinic accepted independently. Grooming capacity 20/20.\n";
} finally {
    if ($created) {
        DB::statement("DROP DATABASE `{$database}`");
    }
}
