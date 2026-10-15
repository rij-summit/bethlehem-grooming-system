<?php

use App\Models\ClinicConfinement;
use App\Services\ClinicServicePriceResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            ClinicConfinement::whereNull('discharged_at')->with(['caseService.clinicService.category', 'appointment.vitals', 'appointment.pet'])
                ->chunkById(100, function ($episodes) {
                    foreach ($episodes as $episode) {
                        $entry = $episode->caseService;
                        if ($entry->applied_price !== null && $entry->service_snapshot) continue;
                        $service = clone $entry->clinicService;
                        // Legacy admissions did not retain a rate. Freeze the available legacy rate once at upgrade.
                        if ($service->tier_basis === 'pet_size') $service->charge_behavior = null;
                        $pricing = app(ClinicServicePriceResolver::class)->resolve($service, $episode->appointment);
                        $entry->forceFill(['applied_price' => $pricing['price'], 'service_snapshot' => $pricing['snapshot']])->save();
                    }
                });
        });
    }

    public function down(): void
    {
        // Retain historical prices even when rolling back the application.
    }
};
