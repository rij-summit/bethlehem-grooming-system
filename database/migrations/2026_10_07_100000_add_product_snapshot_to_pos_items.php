<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->string('item_name', 150)->nullable();
            $table->string('unit', 50)->nullable();
        });

        // Preserve the best available description for sales recorded before snapshots.
        DB::table('pos_transaction_items')->orderBy('id')->chunkById(500, function ($lines) {
            $items = DB::table('inventory_items')->whereIn('item_id', $lines->pluck('item_id'))
                ->get(['item_id', 'item_name', 'unit'])->keyBy('item_id');
            foreach ($lines as $line) {
                if ($item = $items->get($line->item_id)) {
                    DB::table('pos_transaction_items')->where('id', $line->id)
                        ->update(['item_name' => $item->item_name, 'unit' => $item->unit]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_transaction_items', fn (Blueprint $table) => $table->dropColumn(['item_name', 'unit']));
    }
};
