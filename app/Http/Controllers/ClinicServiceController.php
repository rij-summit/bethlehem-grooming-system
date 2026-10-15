<?php

namespace App\Http\Controllers;

use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use App\Models\InventoryItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicServiceController extends Controller
{
    public function caseOptions(Request $request): JsonResponse
    {
        $request->merge(['status' => 'active']);
        return $this->index($request);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => ClinicServiceCategory::orderBy('id')->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:200', 'category_id' => 'nullable|integer|exists:clinic_service_categories,id',
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'page' => 'nullable|integer|min:1',
            'scope' => 'nullable|in:imaging',
        ]);
        $query = ClinicService::with('category');
        if (($filters['scope'] ?? null) === 'imaging') {
            $query->whereHas('category', fn ($q) => $q->where('name', 'Diagnostic Imaging'));
        }
        if ($request->is('api/admin/clinic-service-options')) {
            $query->whereDoesntHave('category', fn ($q) => $q->where('name', 'Confinement'));
        }
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') $query->where('is_active', $status === 'active');
        if (!empty($filters['q'])) $query->where('name', 'like', '%'.$filters['q'].'%');
        if (!empty($filters['category_id'])) $query->where('category_id', $filters['category_id']);
        return response()->json($query->orderBy('name')->orderBy('id')->paginate(10));
    }

    public function store(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            try {
                $service = ClinicService::create($data);
            } catch (UniqueConstraintViolationException $error) {
                $this->duplicateError();
            }
            return response()->json(['data' => $service->load('category')], 201);
        });
    }

    public function update(Request $request, ClinicService $clinicService): JsonResponse
    {
        return DB::transaction(function () use ($request, $clinicService) {
            $data = $this->validated($request, $clinicService);
            try {
                $clinicService->update($data);
            } catch (UniqueConstraintViolationException $error) {
                $this->duplicateError();
            }
            return response()->json(['data' => $clinicService->load('category')]);
        });
    }

    public function status(Request $request, ClinicService $clinicService): JsonResponse
    {
        return DB::transaction(function () use ($request, $clinicService) {
            $data = $request->validate(['is_active' => 'required|boolean']);
            if ($data['is_active']) $this->assertServiceIsNotTreatment($clinicService->name, $clinicService->category_id);
            if ($data['is_active'] && $clinicService->charge_behavior === 'base_confinement') {
                $this->assertSingleBase($clinicService->category_id, $clinicService->id);
            }
            $clinicService->update($data);
            return response()->json(['data' => $clinicService->load('category')]);
        });
    }

    private function validated(Request $request, ?ClinicService $service = null): array
    {
        // Normalize the identity before validation and uniqueness checks.
        $normalize = fn ($value) => is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : $value;
        $request->merge(['name' => $normalize($request->input('name'))]);
        $confinement = ClinicServiceCategory::whereKey($request->input('category_id'))->value('name') === 'Confinement';
        $base = $confinement && $request->input('charge_behavior') === 'base_confinement';
        if ($base) $request->merge(['pricing_method' => 'tiered', 'tier_basis' => 'weight_kg', 'billing_unit' => 'day']);
        $method = $request->input('pricing_method');
        $money = ['required', 'numeric', 'min:0', 'max:'.ClinicService::MAX_PRICE, 'decimal:0,2'];
        $maximumPriceMessage = 'Price must be ₱'.number_format(ClinicService::MAX_PRICE, 2).' or less.';
        $data = $request->validate([
            'name' => 'required|string|max:200', 'category_id' => 'required|integer|exists:clinic_service_categories,id',
            'charge_behavior' => [Rule::excludeIf(! $confinement), 'required', Rule::in(['base_confinement', 'admission_charge', 'additional_charge'])],
            'pricing_method' => ['required', Rule::in(['fixed', 'range', 'starting_at', 'tiered'])],
            'billing_unit' => ['nullable', Rule::in(['service', 'visit', 'day', 'sample', 'pet', 'shot'])],
            'is_active' => 'sometimes|boolean', 'notes' => 'nullable|string|max:5000',
            'price' => [Rule::excludeIf(!in_array($method, ['fixed', 'starting_at'])), ...$money],
            'min_price' => [Rule::excludeIf($method !== 'range'), ...$money],
            'max_price' => [Rule::excludeIf($method !== 'range'), ...$money, 'gte:min_price'],
            'tier_basis' => [Rule::excludeIf($method !== 'tiered'), 'required', Rule::in([$base ? 'weight_kg' : 'pet_size'])],
            'tiers' => [Rule::excludeIf($method !== 'tiered'), 'required', 'array', 'min:'.($base ? 4 : 1), 'max:4'],
            'tiers.*' => $base ? 'array:size,price,max_weight_kg' : 'array:size,price',
            'tiers.*.size' => ['required', 'distinct', Rule::in(['small', 'medium', 'large', 'extra_large'])],
            'tiers.*.price' => $money,
        ], [
            'price.max' => $maximumPriceMessage,
            'min_price.max' => $maximumPriceMessage,
            'max_price.max' => $maximumPriceMessage,
            'tiers.*.price.max' => $maximumPriceMessage,
        ]);
        if ($base) $data['tiers'] = \App\Support\ConfinementWeightPricing::normalize($data['tiers']);
        $data += ['price' => null, 'min_price' => null, 'max_price' => null, 'tier_basis' => null, 'tiers' => null, 'charge_behavior' => null];
        if ($confinement && $data['charge_behavior'] === 'additional_charge'
            && ! in_array($data['billing_unit'] ?? null, [null, 'service', 'day'], true)) {
            throw ValidationException::withMessages(['billing_unit' => 'Use None, Per service or Per day for additional confinement charges.']);
        }
        if ($base && ($data['is_active'] ?? $service?->is_active ?? true)) {
            $this->assertSingleBase((int) $data['category_id'], $service?->id);
        }
        if ($data['is_active'] ?? $service?->is_active ?? true) {
            $this->assertServiceIsNotTreatment($data['name'], (int) $data['category_id']);
        }
        $data['identity_key'] = hash('sha256', json_encode([mb_strtolower($data['name']), (int) $data['category_id']]));
        $duplicate = ClinicService::where('identity_key', $data['identity_key']);
        if ($service) $duplicate->whereKeyNot($service->id);
        if ($duplicate->exists()) $this->duplicateError();
        return $data;
    }

    private function duplicateError(): never
    {
        throw ValidationException::withMessages(['name' => 'A service with this name and category already exists. Edit the existing service instead.']);
    }

    private function assertSingleBase(int $categoryId, ?int $except = null): void
    {
        ClinicServiceCategory::whereKey($categoryId)->lockForUpdate()->firstOrFail();
        if (ClinicService::where('category_id', $categoryId)->where('is_active', true)
            ->where('charge_behavior', 'base_confinement')->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['charge_behavior' => 'There is already an active Base Confinement service. Edit or deactivate it first.']);
        }
    }

    private function assertServiceIsNotTreatment(string $name, int $categoryId): void
    {
        $normalize = fn ($value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
        $name = $normalize($name);
        // Anti Rabies is also an Inventory vaccine name; the certificate is a distinct service.
        if (preg_match('/^anti[ -]?rabies(?: certificate| certification)?$/u', $name)
            && ClinicServiceCategory::whereKey($categoryId)->value('name') === 'Certificates & Forms') return;
        if (preg_match('/\b(triamcinolone|vincristine)\b|\bvaccines?$/u', $name)
            || InventoryItem::whereIn('category', ['medicine', 'vaccine'])->pluck('item_name')
                ->contains(fn ($product) => $normalize($product) === $name)) {
            throw ValidationException::withMessages(['name' => 'Record medicines and vaccines from Inventory through Treatment instead of Clinic Services.']);
        }
    }
}
