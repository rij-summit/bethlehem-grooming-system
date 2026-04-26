<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('time_windows')) {
            Schema::create('time_windows', function (Blueprint $table) {
                $table->increments('window_id');
                $table->string('window_label', 50);
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedTinyInteger('max_slots')->default(4);
                $table->boolean('is_active')->default(1);
            });
        }

        if (!Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table) {
                $table->increments('service_id');
                $table->string('service_name', 150);
                $table->string('slug', 100)->nullable()->unique();
                $table->text('description')->nullable();
                $table->decimal('base_price', 8, 2)->default(0);
                $table->decimal('price_small', 8, 2)->nullable();
                $table->decimal('price_medium', 8, 2)->nullable();
                $table->decimal('price_large', 8, 2)->nullable();
                $table->boolean('is_active')->default(1);
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        if (!Schema::hasTable('addons')) {
            Schema::create('addons', function (Blueprint $table) {
                $table->increments('addon_id');
                $table->string('addon_name', 150);
                $table->text('description')->nullable();
                $table->decimal('price', 8, 2)->default(0);
                $table->boolean('is_active')->default(1);
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        // username, is_archived, archived_at are intentionally excluded —
        // later ALTER TABLE migrations add them and have no hasColumn guards,
        // so including them here would cause a "column already exists" crash.
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->increments('user_id');
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('phone', 20)->unique();
                $table->string('email', 150)->unique();
                $table->string('password_hash');
                $table->enum('role', ['customer', 'staff', 'admin'])->default('customer');
                $table->enum('customer_tier', ['new', 'regular', 'frequent', 'inactive'])->default('new');
                $table->string('profile_photo')->nullable();
                $table->boolean('is_active')->default(1);
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        if (!Schema::hasTable('pets')) {
            Schema::create('pets', function (Blueprint $table) {
                $table->increments('pet_id');
                $table->unsignedInteger('user_id');
                $table->string('pet_name', 100);
                $table->string('species', 50)->default('Dog');
                $table->string('breed', 100)->nullable();
                $table->decimal('weight', 5, 2)->nullable();
                $table->string('color', 50)->nullable();
                $table->enum('size', ['small', 'medium', 'large', 'extra_large'])->nullable();
                $table->enum('fur_type', ['short', 'medium', 'long', 'wire', 'curl'])->nullable();
                $table->text('medical_conditions')->nullable();
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

                $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            });
        }

        // archived_at, paid, dropped_off_at, grooming_started_at, grooming_finished_at
        // are intentionally excluded — same reason as users above.
        // status uses the original 8-value ENUM; ALTER TABLE migrations expand it to the final 13.
        if (!Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->increments('booking_id');
                $table->string('booking_reference', 20)->unique();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('window_id')->nullable();
                $table->date('booking_date');
                $table->unsignedTinyInteger('number_of_pets')->default(1);
                $table->enum('booking_type', ['online', 'walk_in'])->default('online');
                $table->enum('status', [
                    'waiting_to_arrive', 'waiting', 'in_progress',
                    'groomed', 'waiting_for_payment', 'completed', 'cancelled', 'no_show',
                ])->default('waiting_to_arrive');
                $table->integer('queue_number')->nullable();
                $table->text('special_notes')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->decimal('total_amount', 8, 2)->default(0);
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                $table->unsignedTinyInteger('reschedule_count')->default(0);
                $table->unsignedTinyInteger('cancel_count')->default(0);

                $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
                $table->foreign('window_id')->references('window_id')->on('time_windows')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('booking_pets')) {
            Schema::create('booking_pets', function (Blueprint $table) {
                $table->increments('booking_pet_id');
                $table->unsignedInteger('booking_id');
                $table->unsignedInteger('pet_id')->nullable();
                $table->text('special_instructions')->nullable();
                $table->unsignedInteger('groomer_id')->nullable();
                $table->dateTime('grooming_start_time')->nullable();
                $table->dateTime('grooming_end_time')->nullable();

                $table->foreign('booking_id')->references('booking_id')->on('bookings')->onDelete('cascade');
                $table->foreign('pet_id')->references('pet_id')->on('pets')->onDelete('set null');
                $table->foreign('groomer_id')->references('user_id')->on('users')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('booking_services')) {
            Schema::create('booking_services', function (Blueprint $table) {
                $table->increments('booking_service_id');
                $table->unsignedInteger('booking_id');
                $table->unsignedInteger('booking_pet_id');
                $table->unsignedInteger('service_id')->nullable();
                $table->unsignedInteger('addon_id')->nullable();
                $table->decimal('price_at_booking', 8, 2)->default(0);

                $table->foreign('booking_id')->references('booking_id')->on('bookings')->onDelete('cascade');
                $table->foreign('booking_pet_id')->references('booking_pet_id')->on('booking_pets')->onDelete('cascade');
                $table->foreign('service_id')->references('service_id')->on('services')->onDelete('set null');
                $table->foreign('addon_id')->references('addon_id')->on('addons')->onDelete('set null');
            });
        }

        // type uses the original 3-value ENUM; 2026_04_18_100001 and 2026_04_19_000001
        // expand it to the final state of ('booked','cancelled','rescheduled','payment_due').
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->increments('notification_id');
                $table->enum('type', ['booked', 'cancelled', 'rescheduled']);
                $table->unsignedInteger('booking_id');
                $table->text('message');
                $table->boolean('is_read')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('booking_id')->references('booking_id')->on('bookings')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('services');
        Schema::dropIfExists('time_windows');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
