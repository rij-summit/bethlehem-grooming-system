<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCharge;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ClinicMedicineChargeService
{
    // Call inside a transaction with the Clinic case locked.
    public function save(ClinicAppointment $appt, array $entries, User $actor): void
    {
        if (! $entries) return;
        $items = InventoryItem::query()->whereIn('item_id', array_column($entries, 'inventory_item_id'))
            ->orderBy('item_id')->lockForUpdate()->with('weightPrices')->get()->keyBy('item_id');
        $weight = $appt->vitals()->value('weight_kg') ?? $appt->pet?->weight;
        $existing = ClinicCharge::whereIn('request_key', array_column($entries, 'request_key'))->get()->keyBy('request_key');
        $movements = [];
        foreach ($entries as $entry) {
            if ($charge = $existing->get($entry['request_key'])) {
                if ((int) $charge->clinic_appointment_id !== (int) $appt->id
                    || (int) $charge->inventory_item_id !== (int) $entry['inventory_item_id']
                    || $charge->vaccination_record_id !== ($entry['vaccination_record_id'] ?? null)) {
                    throw ValidationException::withMessages(['medicines' => 'This medicine request has already been used. Reload the case.']);
                }
                continue;
            }
            $item = $items->get($entry['inventory_item_id']);
            if (! $item || ! $item->is_active || $item->category !== 'medicine') {
                throw ValidationException::withMessages(['medicines' => 'Choose an active Medicine product from Inventory.']);
            }
            $pricing = $item->clinicPrice($weight);
            if (round((float) $pricing['price'] * 100) !== round((float) $entry['expected_price'] * 100)
                || ($pricing['pet_weight_kg'] !== null && (float) $pricing['pet_weight_kg'] !== (float) ($entry['expected_weight_kg'] ?? 0))) {
                throw ValidationException::withMessages(['medicines' => 'The pet weight or medicine price has changed. Select the medicine again to review its current price.']);
            }
            $charge = $appt->charges()->make([
                'kind' => 'medicine', 'description' => $item->item_name, 'inventory_item_id' => $item->item_id,
                'vaccination_record_id' => $entry['vaccination_record_id'] ?? null,
                'quantity' => 1, 'unit_price' => $pricing['price'], 'amount' => $pricing['price'],
            ]);
            $charge->forceFill([
                'request_key' => $entry['request_key'], 'pet_weight_kg' => $pricing['pet_weight_kg'],
                'min_weight_kg' => $pricing['min_weight_kg'], 'max_weight_kg' => $pricing['max_weight_kg'],
            ])->save();
            // Staff specifies physical stock use independently from the price band.
            if (($entry['inventory_quantity'] ?? null) !== null) {
                $movements[] = [
                    'item_id' => $item->item_id, 'quantity' => $entry['inventory_quantity'], 'reason' => 'used',
                    'unit_cost_at_time' => $item->unit_cost, 'selling_price' => $pricing['price'],
                    'reference_type' => 'clinic', 'reference_id' => $appt->id,
                    'notes' => 'Medicine/treatment · '.$item->item_name,
                ];
            }
        }
        if ($movements) app(InventoryStockMovementService::class)->remove($actor, $movements);
        $total = (float) $appt->charges()->sum('amount');
        if ($total > 99999999.99) throw ValidationException::withMessages(['medicines' => 'The case charges exceed the supported total.']);
        $appt->update(['total_amount' => $total]);
    }
}
