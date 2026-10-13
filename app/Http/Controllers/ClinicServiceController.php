<?php

namespace App\Http\Controllers;

use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicServiceController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => ClinicServiceCategory::orderBy('id')->get(),
            'types' => ClinicService::TYPES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:200', 'category_id' => 'nullable|integer|exists:clinic_service_categories,id',
            'type' => ['nullable', 'string', Rule::in(['__none__', ...ClinicService::TYPES])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'page' => 'nullable|integer|min:1',
        ]);
        $query = ClinicService::with('category');
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') $query->where('is_active', $status === 'active');
        if (!empty($filters['q'])) $query->where('name', 'like', '%'.$filters['q'].'%');
        if (!empty($filters['category_id'])) $query->where('category_id', $filters['category_id']);
        if (!empty($filters['type'])) {
            $filters['type'] === '__none__' ? $query->whereNull('type') : $query->where('type', $filters['type']);
        }
        return response()->json($query->orderBy('name')->orderBy('id')->paginate(10));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        try {
            $service = ClinicService::create($data);
        } catch (UniqueConstraintViolationException $error) {
            $this->duplicateError();
        }
        return response()->json(['data' => $service->load('category')], 201);
    }

    public function update(Request $request, ClinicService $clinicService): JsonResponse
    {
        $data = $this->validated($request, $clinicService);
        try {
            $clinicService->update($data);
        } catch (UniqueConstraintViolationException $error) {
            $this->duplicateError();
        }
        return response()->json(['data' => $clinicService->load('category')]);
    }

    public function status(Request $request, ClinicService $clinicService): JsonResponse
    {
        $clinicService->update($request->validate(['is_active' => 'required|boolean']));
        return response()->json(['data' => $clinicService->load('category')]);
    }

    private function validated(Request $request, ?ClinicService $service = null): array
    {
        // Normalize the identity, including omitted Type, before validation and uniqueness checks.
        $normalize = fn ($value) => is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : $value;
        $type = $normalize($request->input('type'));
        $request->merge(['name' => $normalize($request->input('name')), 'type' => $type === '' ? null : $type]);
        $method = $request->input('pricing_method');
        $money = ['required', 'numeric', 'min:0', 'max:'.ClinicService::MAX_PRICE, 'decimal:0,2'];
        $maximumPriceMessage = 'Price must be ₱'.number_format(ClinicService::MAX_PRICE, 2).' or less.';
        $data = $request->validate([
            'name' => 'required|string|max:200', 'category_id' => 'required|integer|exists:clinic_service_categories,id',
            'type' => ['nullable', Rule::in(ClinicService::TYPES)],
            'pricing_method' => ['required', Rule::in(['fixed', 'range', 'starting_at', 'tiered'])],
            'billing_unit' => ['nullable', Rule::in(['service', 'visit', 'day', 'sample', 'pet'])],
            'is_active' => 'sometimes|boolean', 'notes' => 'nullable|string|max:5000',
            'price' => [Rule::excludeIf(!in_array($method, ['fixed', 'starting_at'])), ...$money],
            'min_price' => [Rule::excludeIf($method !== 'range'), ...$money],
            'max_price' => [Rule::excludeIf($method !== 'range'), ...$money, 'gte:min_price'],
            'tier_basis' => [Rule::excludeIf($method !== 'tiered'), 'required', Rule::in(['pet_size'])],
            'tiers' => [Rule::excludeIf($method !== 'tiered'), 'required', 'array', 'min:1', 'max:4'],
            'tiers.*' => 'array:size,price',
            'tiers.*.size' => ['required', 'distinct', Rule::in(['small', 'medium', 'large', 'extra_large'])],
            'tiers.*.price' => $money,
        ], [
            'price.max' => $maximumPriceMessage,
            'min_price.max' => $maximumPriceMessage,
            'max_price.max' => $maximumPriceMessage,
            'tiers.*.price.max' => $maximumPriceMessage,
        ]);
        $data += ['price' => null, 'min_price' => null, 'max_price' => null, 'tier_basis' => null, 'tiers' => null];
        $data['identity_key'] = hash('sha256', json_encode([mb_strtolower($data['name']), (int) $data['category_id'], mb_strtolower($data['type'] ?? '')]));
        $duplicate = ClinicService::where('identity_key', $data['identity_key']);
        if ($service) $duplicate->whereKeyNot($service->id);
        if ($duplicate->exists()) $this->duplicateError();
        return $data;
    }

    private function duplicateError(): never
    {
        throw ValidationException::withMessages(['name' => 'A service with this name, category and type already exists. Edit the existing service instead.']);
    }
}
