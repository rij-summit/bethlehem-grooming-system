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
            $table->string('kind', 20)->nullable();
            $table->string('pet_type', 10)->nullable();
            $table->json('description_items')->nullable();
            $table->json('included_ala_carte_ids')->nullable();
            $table->unsignedInteger('display_order')->default(0);
        });

        $ids = DB::table('services')->pluck('service_id', 'slug');
        $defaults = json_decode(file_get_contents(database_path('data/grooming-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($defaults as $order => $service) {
            DB::table('services')->where('slug', $service['id'])->update([
                'kind' => $service['kind'], 'pet_type' => $service['petType'] ?? 'all',
                'description_items' => json_encode($service['descriptionItems']),
                'included_ala_carte_ids' => json_encode(array_values(array_filter(array_map(
                    fn ($slug) => $ids[$slug] ?? null, $service['includedAlaCarteServiceIds'] ?? [],
                )))),
                'display_order' => $order,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn([
            'kind', 'pet_type', 'description_items', 'included_ala_carte_ids', 'display_order',
        ]));
    }
};
