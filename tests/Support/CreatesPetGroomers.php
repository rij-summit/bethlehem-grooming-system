<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesPetGroomers
{
    private function createPetGroomers(): void
    {
        Schema::create('pet_groomers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name', 100);
            $table->boolean('on_duty')->default(false);
            $table->timestamps();
        });
        foreach (['Anna', 'Miguel', 'Carlo', 'Bea', 'Nico'] as $index => $name) {
            DB::table('pet_groomers')->insert(['id' => $index + 1, 'first_name' => $name, 'on_duty' => $index < 2]);
        }
    }

    private function setPetGroomersOnDuty(int $count): void
    {
        DB::table('pet_groomers')->update(['on_duty' => false]);
        DB::table('pet_groomers')->where('id', '<=', $count)->update(['on_duty' => true]);
    }
}
