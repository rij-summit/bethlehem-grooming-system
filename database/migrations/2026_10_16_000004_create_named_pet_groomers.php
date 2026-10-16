<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pet_groomers')) Schema::create('pet_groomers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name', 100);
            $table->boolean('on_duty')->default(false);
            $table->timestamps();
        });

        // Preserve every existing assignment and its ID, independently of logins.
        $assigned = DB::table('users')->whereIn('user_id', DB::table('booking_pets')
            ->select('groomer_id')->whereNotNull('groomer_id'))->get();
        foreach ($assigned as $person) {
            DB::table('pet_groomers')->insertOrIgnore([
                'id' => $person->user_id,
                'first_name' => $person->first_name,
                'on_duty' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $keys = collect(Schema::getForeignKeys('booking_pets'))->where('columns', ['groomer_id']);
        foreach ($keys as $key) {
            if ($key['foreign_table'] === 'pet_groomers') continue;
            Schema::table('booking_pets', fn (Blueprint $table) => $table->dropForeign(
                DB::getDriverName() === 'sqlite' ? ['groomer_id'] : $key['name'],
            ));
        }
        if (! $keys->contains('foreign_table', 'pet_groomers')) {
            Schema::table('booking_pets', function (Blueprint $table) {
                $table->foreign('groomer_id')->references('id')->on('pet_groomers')->restrictOnDelete();
            });
        }
        if (Schema::hasColumn('clinic_settings', 'groomers_on_duty')) {
            Schema::table('clinic_settings', fn (Blueprint $table) => $table->dropColumn('groomers_on_duty'));
        }
    }

    public function down(): void
    {
        // New dedicated IDs cannot safely be mapped back to login accounts.
        throw new RuntimeException('Named pet groomer assignments must be preserved. Restore a database backup to revert this migration.');
    }
};
