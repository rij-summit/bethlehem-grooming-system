<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->date('pet_queue_date')->nullable()->after('pet_id');
            $table->unsignedInteger('pet_queue_number')->nullable()->after('pet_queue_date');
        });

        $rows = DB::table('booking_pets as bp')
            ->join('bookings as b', 'b.booking_id', '=', 'bp.booking_id')
            ->leftJoin('pets as p', 'p.pet_id', '=', 'bp.pet_id')
            ->whereNotNull('b.queue_number')
            ->select([
                'bp.booking_pet_id',
                'b.booking_id',
                'b.booking_date',
                'b.dropped_off_at',
                'b.queue_number',
                'p.species',
            ])
            ->get()
            ->groupBy(static function ($row): string {
                return substr((string) ($row->dropped_off_at ?: $row->booking_date), 0, 10);
            });

        foreach ($rows as $queueDate => $dailyPets) {
            $orderedPets = $dailyPets->sort(static function ($left, $right): int {
                $speciesRank = static fn ($species): int => match (strtolower((string) $species)) {
                    'dog' => 0,
                    'cat' => 1,
                    default => 2,
                };

                return ((int) $left->queue_number <=> (int) $right->queue_number)
                    ?: ((int) $left->booking_id <=> (int) $right->booking_id)
                    ?: ($speciesRank($left->species) <=> $speciesRank($right->species))
                    ?: ((int) $left->booking_pet_id <=> (int) $right->booking_pet_id);
            });

            $queueNumber = 1;
            foreach ($orderedPets as $pet) {
                DB::table('booking_pets')
                    ->where('booking_pet_id', $pet->booking_pet_id)
                    ->update([
                        'pet_queue_date' => $queueDate,
                        'pet_queue_number' => $queueNumber++,
                    ]);
            }
        }

        Schema::table('booking_pets', function (Blueprint $table) {
            $table->unique(
                ['pet_queue_date', 'pet_queue_number'],
                'booking_pets_daily_queue_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->dropUnique('booking_pets_daily_queue_unique');
            $table->dropColumn(['pet_queue_date', 'pet_queue_number']);
        });
    }
};
