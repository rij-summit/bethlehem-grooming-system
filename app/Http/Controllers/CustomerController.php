<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnregisteredCustomerRequest;
use App\Models\Booking;
use App\Models\Pet;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
    // Query params: status (active|inactive|archived|unregistered), tier (new|returning), search
    public function index(Request $request)
    {
        $this->requireAdminOrStaff($request);

        $status = $request->query('status', 'active');   // active | inactive | archived | unregistered
        $tier = $request->query('tier', '');          // new | returning | '' (all)
        $search = trim((string) $request->query('search', ''));

        if ($status === 'unregistered') {
            return $this->unregisteredIndex($search);
        }

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
                'recordType' => 'registered',
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

        if ($status === 'archived' && Schema::hasTable('unregistered_customers')) {
            $customers = $customers
                ->concat($this->queryUnregisteredCustomers($search, true))
                ->sortBy('fullName', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        }

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

    private function unregisteredIndex(string $search)
    {
        $customers = $this->queryUnregisteredCustomers($search, false);

        return response()->json([
            'success' => true,
            'customers' => $customers,
            'total' => $customers->count(),
            'pets' => [],
            'petTotal' => 0,
        ]);
    }

    private function queryUnregisteredCustomers(string $search, bool $archived)
    {
        $query = UnregisteredCustomer::query()
            ->with('pets')
            ->where('is_archived', $archived);

        if ($search !== '') {
            $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery
                    ->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere(function ($nameQuery) use ($nameTerms) {
                        foreach ($nameTerms as $term) {
                            $nameQuery->where(function ($termQuery) use ($term) {
                                $termQuery->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('middle_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%");
                            });
                        }
                    });
            });
        }

        return $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (UnregisteredCustomer $customer) => $this->formatUnregisteredCustomer($customer));
    }

    public function storeUnregistered(StoreUnregisteredCustomerRequest $request)
    {
        $this->requireAdminOrStaff($request);
        $data = $request->validated();

        $registeredMatch = User::query()
            ->where('role', 'customer')
            ->where(function ($query) use ($data) {
                $query->where('phone', $data['phone']);

                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
            })
            ->first();

        if ($registeredMatch) {
            $field = $registeredMatch->phone === $data['phone'] ? 'phone' : 'email';

            throw ValidationException::withMessages([
                $field => 'A registered customer already uses this '.($field === 'phone' ? 'phone number.' : 'email address.'),
            ]);
        }

        $unregisteredMatch = UnregisteredCustomer::query()
            ->where(function ($query) use ($data) {
                $query->where('phone', $data['phone']);

                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
            })
            ->first();

        if ($unregisteredMatch) {
            $field = $unregisteredMatch->phone === $data['phone'] ? 'phone' : 'email';

            throw ValidationException::withMessages([
                $field => 'An unregistered customer already uses this '.($field === 'phone' ? 'phone number.' : 'email address.'),
            ]);
        }

        $similarCustomers = $this->findCustomersWithSimilarName(
            $data['first_name'],
            $data['last_name'],
        );

        if ($similarCustomers->isNotEmpty() && ! ($data['confirm_similar_name'] ?? false)) {
            return response()->json([
                'success' => false,
                'code' => 'similar_customer_name',
                'message' => 'A customer with the same first and last name already exists. Do you still want to continue?',
                'similarCustomers' => $similarCustomers,
            ], 409);
        }

        unset($data['confirm_similar_name']);

        $customer = UnregisteredCustomer::create([
            ...$data,
            'created_by_user_id' => $request->user()->user_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Unregistered customer added successfully.',
            'customer' => $this->formatUnregisteredCustomer($customer),
        ], 201);
    }

    public function showUnregistered(Request $request, $id)
    {
        $this->requireAdminOrStaff($request);

        $customer = UnregisteredCustomer::query()->with('pets')->find($id);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Unregistered customer not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'customer' => $this->formatUnregisteredCustomer($customer),
        ]);
    }

    private function formatUnregisteredCustomer(UnregisteredCustomer $customer): array
    {
        $middleName = trim((string) $customer->middle_name);
        $displayMiddleName = $middleName !== '' ? rtrim($middleName, '.').'.' : '';
        $fullName = trim(implode(' ', array_filter([
            $customer->first_name,
            $displayMiddleName,
            $customer->last_name,
        ])));

        $pets = $customer->relationLoaded('pets')
            ? $customer->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values()
            : collect();

        return [
            'id' => $customer->id,
            'recordType' => 'unregistered',
            'status' => $customer->is_archived ? 'archived' : 'unregistered',
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'middleName' => $customer->middle_name,
            'fullName' => $fullName,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'isActive' => false,
            'isArchived' => (bool) $customer->is_archived,
            'tier' => null,
            'bookingCount' => 0,
            'petCount' => $pets->count(),
            'activePetCount' => $pets->where('isArchived', false)->count(),
            'archivedPetCount' => $pets->where('isArchived', true)->count(),
            'pets' => $pets,
            'archivedAt' => $customer->archived_at,
            'joinedAt' => $customer->created_at,
        ];
    }

    private function formatPet(Pet $pet): array
    {
        return [
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
        ];
    }

    private function findCustomersWithSimilarName(string $firstName, string $lastName)
    {
        $normalizedFirst = mb_strtolower(trim($firstName));
        $normalizedLast = mb_strtolower(trim($lastName));

        $registered = User::query()
            ->where('role', 'customer')
            ->whereRaw('LOWER(first_name) = ?', [$normalizedFirst])
            ->whereRaw('LOWER(last_name) = ?', [$normalizedLast])
            ->get()
            ->map(fn (User $user) => [
                'fullName' => trim($user->first_name.' '.$user->last_name),
                'phone' => $user->phone,
                'status' => $user->is_archived ? 'Archived' : ($user->is_active ? 'Active' : 'Inactive'),
            ]);

        $unregistered = UnregisteredCustomer::query()
            ->whereRaw('LOWER(first_name) = ?', [$normalizedFirst])
            ->whereRaw('LOWER(last_name) = ?', [$normalizedLast])
            ->get()
            ->map(fn (UnregisteredCustomer $customer) => [
                'fullName' => trim($customer->first_name.' '.$customer->last_name),
                'phone' => $customer->phone,
                'status' => $customer->is_archived ? 'Archived' : 'Unregistered',
            ]);

        return $registered->concat($unregistered)->values();
    }

    public function archiveUnregistered(Request $request, $id)
    {
        $this->requireAdminOrStaff($request);

        $customer = UnregisteredCustomer::query()->find($id);
        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Unregistered customer not found.'], 404);
        }
        if ($customer->is_archived) {
            return response()->json(['success' => false, 'message' => 'Customer is already archived.'], 422);
        }

        $customer->update(['is_archived' => true, 'archived_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Customer archived.']);
    }

    public function walkInSearch(Request $request)
    {
        $this->requireAdminOrStaff($request);
        $validated = $request->validate(['q' => ['required', 'string', 'max:100']]);
        $search = trim($validated['q']);

        if (mb_strlen($search) < 2) {
            return response()->json(['success' => true, 'customers' => []]);
        }

        $nameTerms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $applySearch = function ($query) use ($search, $nameTerms) {
            $query->where(function ($customerQuery) use ($search, $nameTerms) {
                $customerQuery->where('phone', 'like', "%{$search}%")
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
        };

        $registeredQuery = User::query()
            ->with(['pets' => fn ($query) => $query->where('is_archived', false)])
            ->where('role', 'customer')
            ->where('is_active', true)
            ->where('is_archived', false);
        $applySearch($registeredQuery);
        $registered = $registeredQuery->limit(10)->get()->map(fn (User $user) => [
            'id' => $user->user_id,
            'recordType' => 'registered',
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'middleName' => null,
            'fullName' => trim($user->first_name.' '.$user->last_name),
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => 'active',
            'pets' => $user->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
        ]);

        $unregisteredQuery = UnregisteredCustomer::query()
            ->with(['pets' => fn ($query) => $query->where('is_archived', false)])
            ->where('is_archived', false);
        $applySearch($unregisteredQuery);
        $unregistered = $unregisteredQuery->limit(10)->get()->map(fn (UnregisteredCustomer $customer) => [
            'id' => $customer->id,
            'recordType' => 'unregistered',
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'middleName' => $customer->middle_name,
            'fullName' => trim($customer->first_name.' '.$customer->last_name),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => 'unregistered',
            'pets' => $customer->pets->map(fn (Pet $pet) => $this->formatPet($pet))->values(),
        ]);

        return response()->json([
            'success' => true,
            'customers' => $registered->concat($unregistered)
                ->sortBy('fullName', SORT_NATURAL | SORT_FLAG_CASE)
                ->take(12)
                ->values(),
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
