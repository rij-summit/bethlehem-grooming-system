<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('starting_price_sizes')->nullable();
        });

        // Freeze legacy defaults into the live catalogue once, preserving usable DB amounts.
        foreach (config('grooming_services.services') as $slug => $defaults) {
            $service = DB::table('services')->where('slug', $slug)->first();
            if (! $service) {
                continue;
            }
            $values = ['starting_price_sizes' => json_encode($defaults['starting_sizes'] ?? [])];
            foreach ($defaults['prices'] ?? [] as $size => $amount) {
                $column = 'price_'.$size;
                if ((float) ($service->$column ?? 0) <= 0) {
                    $values[$column] = $amount;
                }
            }
            if ((float) ($service->base_price ?? 0) <= 0) {
                $values['base_price'] = $defaults['default'] ?? $defaults['minimum'];
            }
            if (($defaults['kind'] ?? null) === 'ala_carte') {
                if ((float) ($service->price_min ?? 0) <= 0 && isset($defaults['minimum'])) {
                    $values['price_min'] = $defaults['minimum'];
                    $values['price_max'] = $defaults['maximum'] ?? null;
                }
                $values['is_starting_price'] = (bool) ($service->is_starting_price
                    || ($defaults['starting_price'] ?? false)
                    || ((float) ($service->price_min ?? 0) > 0 && (float) ($service->price_max ?? 0) <= 0));
            }
            DB::table('services')->where('service_id', $service->service_id)->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('starting_price_sizes'));
    }
};
