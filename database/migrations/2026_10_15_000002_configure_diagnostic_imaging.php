<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_attachments', function (Blueprint $table) {
            // Removing a billable occurrence preserves its medical files on the record.
            $table->foreignId('clinic_case_service_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::transaction(function () {
            $oldId = DB::table('clinic_service_categories')->where('name', 'Imaging')->value('id');
            $id = DB::table('clinic_service_categories')->where('name', 'Diagnostic Imaging')->value('id');
            if ($oldId && ! $id) {
                DB::table('clinic_service_categories')->where('id', $oldId)->update(['name' => 'Diagnostic Imaging']);
                $id = $oldId;
            }
            $id ??= DB::table('clinic_service_categories')->insertGetId(['name' => 'Diagnostic Imaging']);
            if ($oldId && $oldId !== $id) {
                DB::table('clinic_services')->where('category_id', $oldId)->update(['category_id' => $id]);
                DB::table('clinic_service_categories')->where('id', $oldId)->delete();
            }

            // Catalogue configuration only. Runtime pricing always uses ClinicServicePriceResolver.
            $catalogue = [
                ['X-ray — Abdominal', 1950, 'service'],
                ['X-ray — Thoracic', 1950, 'service'],
                ['X-ray — Abdominal + Thoracic', 3250, 'service'],
                ['X-ray — 2nd Session (Confined)', 650, 'service'],
                ['X-ray Referral — Abdominal (No PF)', 1600, 'service'],
                ['X-ray Referral — Thoracic (No PF)', 1600, 'service'],
                ['X-ray Referral — Abdominal + Thoracic (No PF)', 3000, 'service'],
                ['X-ray — Additional Shot', 350, 'shot'],
                ['Ultrasound — Abdominal', 2050, 'service'],
                ['Ultrasound — Thoracic', 2000, 'service'],
                ['Ultrasound — 2nd Session (Confined)', 1000, 'service'],
            ];
            $normalize = function ($name) {
                $name = mb_strtolower($name);
                $name = str_replace(['w/o pf', 'without pf'], 'no pf', $name);
                return trim(preg_replace('/[^\pL\pN]+/u', ' ', $name));
            };
            $existing = DB::table('clinic_services')->where('category_id', $id)->orderByDesc('is_active')->orderBy('id')->get();
            foreach ($catalogue as [$name, $price, $unit]) {
                $matches = $existing->filter(fn ($row) => $normalize($row->name) === $normalize($name));
                $values = ['name' => $name, 'category_id' => $id, 'pricing_method' => 'fixed', 'billing_unit' => $unit,
                    'price' => $price, 'min_price' => null, 'max_price' => null, 'tier_basis' => null, 'tiers' => null,
                    'identity_key' => hash('sha256', json_encode([mb_strtolower($name), (int) $id])), 'updated_at' => now()];
                if ($matches->isNotEmpty()) {
                    $row = $matches->first();
                    foreach ($matches->skip(1) as $duplicate) {
                        DB::table('clinic_services')->where('id', $duplicate->id)->update([
                            'is_active' => false, 'identity_key' => hash('sha256', 'legacy-imaging:'.$duplicate->id),
                        ]);
                    }
                    DB::table('clinic_services')->where('id', $row->id)->update($values);
                } else {
                    DB::table('clinic_services')->insert([...$values, 'is_active' => true, 'created_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinic_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('clinic_case_service_id'));
        // Keep catalogue IDs and historical service snapshots intact.
    }
};
