<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'price_extra_large')) {
                $table->decimal('price_extra_large', 8, 2)->nullable();
            }

            if (! Schema::hasColumn('services', 'price_min')) {
                $table->decimal('price_min', 8, 2)->nullable();
            }

            if (! Schema::hasColumn('services', 'price_max')) {
                $table->decimal('price_max', 8, 2)->nullable();
            }

            if (! Schema::hasColumn('services', 'is_starting_price')) {
                $table->boolean('is_starting_price')->default(false);
            }
        });

        $livePricing = [
            'partial_grooming' => ['price_extra_large' => 700],
            'regular_dog_grooming' => ['price_extra_large' => 1050],
            'deluxe_dog_grooming' => ['price_extra_large' => 1200],
            'bath_and_go' => ['price_extra_large' => 750],
            'nail_clipping' => ['price_min' => 50, 'price_max' => 100],
            'ear_cleaning' => ['is_starting_price' => true],
            'tooth_brushing' => ['is_starting_price' => true],
        ];

        foreach ($livePricing as $slug => $values) {
            DB::table('services')->where('slug', $slug)->update($values);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        $columns = collect([
            'price_extra_large',
            'price_min',
            'price_max',
            'is_starting_price',
        ])->filter(fn (string $column) => Schema::hasColumn('services', $column));

        if ($columns->isNotEmpty()) {
            Schema::table('services', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns->all());
            });
        }
    }
};
