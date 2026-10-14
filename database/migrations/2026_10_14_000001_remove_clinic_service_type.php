<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $normalize = fn ($name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
            $category = function ($old, $name) {
                $oldId = DB::table('clinic_service_categories')->where('name', $old)->value('id');
                $id = DB::table('clinic_service_categories')->where('name', $name)->value('id');
                if ($oldId && ! $id) {
                    DB::table('clinic_service_categories')->where('id', $oldId)->update(['name' => $name]);
                    return $oldId;
                }
                $id ??= DB::table('clinic_service_categories')->insertGetId(['name' => $name]);
                if ($oldId && $oldId !== $id) {
                    DB::table('clinic_services')->where('category_id', $oldId)->update(['category_id' => $id]);
                    DB::table('clinic_service_categories')->where('id', $oldId)->delete();
                }
                return $id;
            };
            // Retain catalogue rows and all historical foreign keys and snapshots.
            $boarding = DB::table('clinic_service_categories')->where('name', 'Boarding & Lodging')->value('id');
            if ($boarding) DB::table('clinic_services')->where('category_id', $boarding)->update(['is_active' => false]);
            $diagnostics = $category('Diagnostics', 'Diagnostic Tests');
            $category('Boarding & Lodging', 'Confinement');
            $certificates = DB::table('clinic_service_categories')->where('name', 'Certificates & Forms')->value('id');
            $imaging = DB::table('clinic_service_categories')->where('name', 'Imaging')->value('id');
            $products = Schema::hasTable('inventory_items')
                ? DB::table('inventory_items')->whereIn('category', ['medicine', 'vaccine'])->pluck('item_name')->map($normalize)->all()
                : [];
            foreach (DB::table('clinic_services')->orderBy('id')->get() as $service) {
                $name = $normalize($service->name);
                $certificate = preg_match('/^anti[ -]?rabies(?: certificate| certification)?$/u', $name);
                $changes = [];
                if ($certificate && $certificates) {
                    $changes['category_id'] = $certificates;
                } elseif ((int) $service->category_id !== (int) $imaging
                    && (int) $service->category_id !== (int) $certificates) {
                    if (in_array($name, $products, true)
                        || preg_match('/\b(triamcinolone|vincristine)\b|\bvaccines?$/u', $name)) {
                        $changes['is_active'] = false;
                    } elseif ($service->type === 'Test Kit'
                        || preg_match('/^(cpv|cdv|chw|fiv[\/ -]felv|snap test|cpl|sdma|saa)(?:\b|\/)/u', $name)) {
                        $changes['category_id'] = $diagnostics;
                    }
                }
                if (preg_match('/\b(boarding|lodging)\b/u', $name)) $changes['is_active'] = false;
                // Free the old unique identities before removing Type from their definition.
                $changes['identity_key'] = hash('sha256', 'legacy-clinic-service:'.$service->id);
                DB::table('clinic_services')->where('id', $service->id)->update($changes);
            }
            $seen = [];
            foreach (DB::table('clinic_services')->orderByDesc('is_active')->orderBy('id')->get() as $service) {
                $identity = hash('sha256', json_encode([$normalize($service->name), (int) $service->category_id]));
                if (isset($seen[$identity])) {
                    // Keep references intact; only one equivalent service remains selectable.
                    DB::table('clinic_services')->where('id', $service->id)->update(['is_active' => false]);
                } else {
                    DB::table('clinic_services')->where('id', $service->id)->update(['identity_key' => $identity]);
                    $seen[$identity] = true;
                }
            }
        });
        Schema::table('clinic_services', fn (Blueprint $table) => $table->dropColumn('type'));
    }

    public function down(): void
    {
        // Removed catalogue Type values cannot be reconstructed. Historical JSON is untouched.
        Schema::table('clinic_services', fn (Blueprint $table) => $table->string('type', 100)->nullable());
    }
};
