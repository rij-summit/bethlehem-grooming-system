<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    private function requireAuth(): \App\Models\User
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        return $user;
    }

    private function formatSupplier(Supplier $s): array
    {
        return [
            'supplier_id'    => $s->supplier_id,
            'supplier_name'  => $s->supplier_name,
            'contact_person' => $s->contact_person,
            'phone'          => $s->phone,
            'email'          => $s->email,
            'address'        => $s->address,
            'notes'          => $s->notes,
            'is_active'      => $s->is_active,
            'created_at'     => $s->created_at,
        ];
    }

    // List active suppliers (used in stock-in dropdown)
    public function index(Request $request): JsonResponse
    {
        $this->requireAuth();

        $q = trim($request->query('q', ''));

        $query = Supplier::where('is_active', 1)->orderBy('supplier_name');

        if ($q) {
            $query->where('supplier_name', 'like', "%{$q}%");
        }

        // For dropdowns, return all; for management pages, paginate
        if ($request->boolean('paginate')) {
            $page      = max(1, $request->integer('page', 1));
            $paginator = $query->paginate(20, ['*'], 'page', $page);

            return response()->json([
                'data'      => collect($paginator->items())->map(fn ($s) => $this->formatSupplier($s)),
                'total'     => $paginator->total(),
                'page'      => $page,
                'last_page' => $paginator->lastPage(),
            ]);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($s) => $this->formatSupplier($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAuth();

        $validated = $request->validate([
            'supplier_name'  => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'address'        => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $supplier = Supplier::create([...$validated, 'is_active' => 1]);

        return response()->json(['data' => $this->formatSupplier($supplier)], 201);
    }

    public function update(Request $request, int $supplierId): JsonResponse
    {
        $this->requireAuth();

        $supplier = Supplier::findOrFail($supplierId);

        $validated = $request->validate([
            'supplier_name'  => 'sometimes|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'address'        => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $supplier->update($validated);

        return response()->json(['data' => $this->formatSupplier($supplier->fresh())]);
    }

    public function deactivate(int $supplierId): JsonResponse
    {
        $this->requireAuth();

        $supplier = Supplier::findOrFail($supplierId);
        $supplier->update(['is_active' => 0]);

        return response()->json(['message' => 'Supplier deactivated.']);
    }
}
