<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // ── ADMIN GUARD ───────────────────────────────────────
    private function requireAdmin(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403));
        }
    }

    private function requireAdminOrStaff(Request $request)
    {
        if (! in_array($request->user()?->role, ['admin', 'staff'], true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff access required.',
            ], 403));
        }
    }

    // ── LIST CUSTOMERS ────────────────────────────────────
    // GET /api/admin/customers
    // Query params: status (active|inactive|archived), tier (new|returning), search
    public function index(Request $request)
    {
        $this->requireAdminOrStaff($request);

        $status = $request->query('status', 'active');   // active | inactive | archived
        $tier = $request->query('tier', '');          // new | returning | '' (all)
        $search = trim((string) $request->query('search', ''));

        $query = User::where('role', 'customer');

        $this->applyCustomerStatusFilter($query, $status);

        // Search
        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($q) use ($search, $nameTerms) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            });
        }

        $users = $query->orderBy('first_name')->get();

        // Attach booking counts and compute tier
        $customers = $users->map(function (User $user) use ($tier) {
            $bookingCount = Booking::where('user_id', $user->user_id)
                ->whereNotIn('status', ['cancelled'])
                ->count();

            $pastBookingCount = Booking::where('user_id', $user->user_id)
                ->whereNotIn('status', ['cancelled', 'waiting_to_arrive'])
                ->count();

            $computedTier = $pastBookingCount > 0 ? 'returning' : 'new';

            // Apply tier filter after computing
            if ($tier && $computedTier !== $tier) {
                return null;
            }

            return [
                'id' => $user->user_id,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'isArchived' => (bool) $user->is_archived,
                'tier' => $computedTier,
                'bookingCount' => $bookingCount,
                'archivedAt' => $user->archived_at,
                'joinedAt' => $user->created_at ?? null,
            ];
        })->filter()->values();

        $petMatches = collect();

        if ($search !== '') {
            $petMatches = Pet::query()
                ->with('user:user_id,first_name,last_name,phone,email')
                ->where('pet_name', 'like', "%{$search}%")
                ->whereHas('user', function ($ownerQuery) use ($status) {
                    $ownerQuery->where('role', 'customer');
                    $this->applyCustomerStatusFilter($ownerQuery, $status);
                })
                ->orderBy('pet_name')
                ->orderBy('pet_id')
                ->get();

            if ($tier !== '') {
                $ownerTiers = [];
                $petMatches = $petMatches->filter(function (Pet $pet) use ($tier, &$ownerTiers) {
                    if (! array_key_exists($pet->user_id, $ownerTiers)) {
                        $hasPastBooking = Booking::where('user_id', $pet->user_id)
                            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive'])
                            ->exists();
                        $ownerTiers[$pet->user_id] = $hasPastBooking ? 'returning' : 'new';
                    }

                    return $ownerTiers[$pet->user_id] === $tier;
                });
            }

            $petMatches = $petMatches->map(fn (Pet $pet) => [
                'id' => $pet->pet_id,
                'petName' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'isArchived' => (bool) $pet->is_archived,
                'ownerId' => $pet->user_id,
                'ownerName' => trim($pet->user->first_name.' '.$pet->user->last_name),
                'ownerPhone' => $pet->user->phone,
                'ownerEmail' => $pet->user->email,
            ])->values();
        }

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'total' => $customers->count(),
            'pets' => $petMatches,
            'petTotal' => $petMatches->count(),
        ]);
    }

    public function dashboardSearch(Request $request)
    {
        $this->requireAdminOrStaff($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        if ($search === '') {
            return response()->json([
                'success' => true,
                'customers' => [],
                'pets' => [],
            ]);
        }

        $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $customers = User::query()
            ->where('role', 'customer')
            ->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery->where('phone', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(8)
            ->get(['user_id', 'first_name', 'last_name', 'phone'])
            ->map(fn (User $user) => [
                'id' => $user->user_id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'phone' => $user->phone,
            ]);

        $pets = Pet::query()
            ->with('user:user_id,first_name,last_name')
            ->where('pet_name', 'like', "%{$search}%")
            ->whereHas('user', fn ($ownerQuery) => $ownerQuery->where('role', 'customer'))
            ->orderBy('pet_name')
            ->orderBy('pet_id')
            ->limit(8)
            ->get(['pet_id', 'user_id', 'pet_name', 'species'])
            ->map(fn (Pet $pet) => [
                'id' => $pet->pet_id,
                'name' => $pet->pet_name,
                'species' => $pet->species,
                'ownerId' => $pet->user_id,
                'ownerName' => trim($pet->user->first_name.' '.$pet->user->last_name),
            ]);

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'pets' => $pets,
        ]);
    }

    private function applyCustomerStatusFilter($query, string $status): void
    {
        if ($status === 'archived') {
            $query->where('is_archived', 1);

            return;
        }

        if ($status === 'inactive') {
            $query->where('is_archived', 0)->where('is_active', 0);

            return;
        }

        $query->where('is_archived', 0)->where('is_active', 1);
    }

    // ── CUSTOMER DETAILS ──────────────────────────────────
    // GET /api/admin/customers/{id}
    public function show(Request $request, $id)
    {
        $this->requireAdminOrStaff($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        $bookingCount = Booking::where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $pastBookingCount = Booking::where('user_id', $user->user_id)
            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive'])
            ->count();

        $pets = Pet::where('user_id', $user->user_id)
            ->orderBy('is_archived')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Pet $pet) => [
                'id' => $pet->pet_id,
                'petName' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'gender' => $pet->gender,
                'birthdate' => $pet->birthdate,
                'isNeutered' => (bool) $pet->is_neutered,
                'neuteredDate' => $pet->neutered_date,
                'isDeceased' => (bool) $pet->is_deceased,
                'deceasedDate' => $pet->deceased_date,
                'weight' => $pet->weight,
                'color' => $pet->color,
                'size' => $pet->size,
                'furType' => $pet->fur_type,
                'medicalConditions' => $pet->medical_conditions,
                'isArchived' => (bool) $pet->is_archived,
                'createdAt' => $pet->created_at ?? null,
            ]);

        return response()->json([
            'success' => true,
            'customer' => [
                'id' => $user->user_id,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'isArchived' => (bool) $user->is_archived,
                'tier' => $pastBookingCount > 0 ? 'returning' : 'new',
                'customerTier' => $user->customer_tier,
                'bookingCount' => $bookingCount,
                'petCount' => $pets->count(),
                'activePetCount' => $pets->where('isArchived', false)->count(),
                'archivedPetCount' => $pets->where('isArchived', true)->count(),
                'archivedAt' => $user->archived_at,
                'joinedAt' => $user->created_at ?? null,
                'pets' => $pets->values(),
            ],
        ]);
    }

    // ── DEACTIVATE ────────────────────────────────────────
    // POST /api/admin/customers/{id}/deactivate
    public function deactivate(Request $request, $id)
    {
        $this->requireAdmin($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if (! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Customer is already inactive.'], 422);
        }

        $user->update(['is_active' => 0]);

        // Revoke all active tokens so they are logged out immediately
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Customer account deactivated.']);
    }

    // ── REACTIVATE ────────────────────────────────────────
    // POST /api/admin/customers/{id}/reactivate
    public function reactivate(Request $request, $id)
    {
        $this->requireAdmin($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if ($user->is_archived) {
            return response()->json(['success' => false, 'message' => 'Unarchive the account before reactivating.'], 422);
        }

        $user->update(['is_active' => 1]);

        return response()->json(['success' => true, 'message' => 'Customer account reactivated.']);
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // POST /api/admin/customers/{id}/archive
    public function archive(Request $request, $id)
    {
        $this->requireAdmin($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if ($user->is_archived) {
            return response()->json(['success' => false, 'message' => 'Customer is already archived.'], 422);
        }

        $user->update([
            'is_archived' => 1,
            'is_active' => 0,
            'archived_at' => now(),
        ]);

        // Revoke all active tokens
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Customer account archived.']);
    }

    // ── UNARCHIVE ─────────────────────────────────────────
    // POST /api/admin/customers/{id}/unarchive
    public function unarchive(Request $request, $id)
    {
        $this->requireAdmin($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if (! $user->is_archived) {
            return response()->json(['success' => false, 'message' => 'Customer is not archived.'], 422);
        }

        $user->update([
            'is_archived' => 0,
            'is_active' => 1,
            'archived_at' => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Customer account unarchived and reactivated.']);
    }
}
