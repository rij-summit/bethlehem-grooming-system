<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Booking;

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

    // ── LIST CUSTOMERS ────────────────────────────────────
    // GET /api/admin/customers
    // Query params: status (active|inactive|archived), tier (new|returning), search
    public function index(Request $request)
    {
        $this->requireAdmin($request);

        $status = $request->query('status', 'active');   // active | inactive | archived
        $tier   = $request->query('tier',   '');          // new | returning | '' (all)
        $search = $request->query('search', '');

        $query = User::where('role', 'customer');

        // Status filter
        if ($status === 'archived') {
            $query->where('is_archived', 1);
        } elseif ($status === 'inactive') {
            $query->where('is_archived', 0)->where('is_active', 0);
        } else {
            // active (default)
            $query->where('is_archived', 0)->where('is_active', 1);
        }

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%");
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
                'id'           => $user->user_id,
                'firstName'    => $user->first_name,
                'lastName'     => $user->last_name,
                'fullName'     => trim($user->first_name . ' ' . $user->last_name),
                'email'        => $user->email,
                'phone'        => $user->phone,
                'isActive'     => (bool) $user->is_active,
                'isArchived'   => (bool) $user->is_archived,
                'tier'         => $computedTier,
                'bookingCount' => $bookingCount,
                'archivedAt'   => $user->archived_at,
                'joinedAt'     => $user->created_at ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'success'   => true,
            'customers' => $customers,
            'total'     => $customers->count(),
        ]);
    }

    // ── DEACTIVATE ────────────────────────────────────────
    // POST /api/admin/customers/{id}/deactivate
    public function deactivate(Request $request, $id)
    {
        $this->requireAdmin($request);

        $user = User::where('user_id', $id)->where('role', 'customer')->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if (!$user->is_active) {
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

        if (!$user) {
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

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if ($user->is_archived) {
            return response()->json(['success' => false, 'message' => 'Customer is already archived.'], 422);
        }

        $user->update([
            'is_archived' => 1,
            'is_active'   => 0,
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

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }

        if (!$user->is_archived) {
            return response()->json(['success' => false, 'message' => 'Customer is not archived.'], 422);
        }

        $user->update([
            'is_archived' => 0,
            'is_active'   => 1,
            'archived_at' => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Customer account unarchived and reactivated.']);
    }
}
