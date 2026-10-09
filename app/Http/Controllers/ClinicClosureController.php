<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use App\Models\Booking;

class ClinicClosureController extends Controller
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

    // ── GET CLINIC STATUS ─────────────────────────────────
    // Returns today's stop_today status + all upcoming blocked dates
    // PUBLIC — used by the customer booking form
    public function status()
    {
        $today = Carbon::today()->toDateString();
        $settings = ClinicSetting::current();

        $stoppedToday = ClinicClosure::where('type', 'stop_today')
            ->whereDate('start_date', $today)
            ->where('is_active', 1)
            ->exists();

        $blockedDates = ClinicClosure::where('type', 'blocked_date')
            ->where('is_active', 1)
            ->where('end_date', '>=', $today)
            ->orderBy('start_date')
            ->get()
            ->map(fn($c) => [
                'id'         => $c->id,
                'start_date' => $c->start_date->toDateString(),
                'end_date'   => $c->end_date->toDateString(),
                'reason'     => $c->reason,
            ]);

        return response()->json([
            'success'       => true,
            'stopped_today' => $stoppedToday,
            'blocked_dates' => $blockedDates,
            'availability'  => $settings->availabilityPayload(),
        ]);
    }

    // ── STOP RECEIVING TODAY ──────────────────────────────
    // POST /api/admin/clinic/stop-today
    public function stopToday(Request $request)
    {
        $this->requireAdmin($request);

        $today = Carbon::today()->toDateString();

        return app(\App\Services\DailyPetQueue::class)->runForDate($today, function () use ($request, $today) {
            // Already stopped
            if (ClinicClosure::where('type', 'stop_today')->whereDate('start_date', $today)->where('is_active', 1)->exists()) {
                return response()->json(['success' => false, 'message' => 'Already stopped for today.'], 422);
            }

            ClinicClosure::create([
                'type'       => 'stop_today',
                'start_date' => $today,
                'end_date'   => $today,
                'is_active'  => 1,
                'created_by' => $request->user()->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bethlehem has stopped receiving Clinic and Grooming customers for today.',
            ]);
        });
    }

    // ── REOPEN TODAY ──────────────────────────────────────
    // POST /api/admin/clinic/reopen-today
    public function reopenToday(Request $request)
    {
        $this->requireAdmin($request);

        $today = Carbon::today()->toDateString();

        return app(\App\Services\DailyPetQueue::class)->runForDate($today, function () use ($request, $today) {
            $closure = ClinicClosure::where('type', 'stop_today')
                ->whereDate('start_date', $today)
                ->where('is_active', 1)
                ->first();

            if (!$closure) {
                return response()->json(['success' => false, 'message' => 'Clinic is not stopped today.'], 422);
            }

            $closure->update(['is_active' => 0]);

            return response()->json([
                'success' => true,
                'message' => 'Bethlehem is receiving Clinic and Grooming customers again today.',
            ]);
        });
    }

    // ── LIST BLOCKED DATES ────────────────────────────────
    // GET /api/admin/clinic/blocked-dates
    public function blockedDates()
    {
        $dates = ClinicClosure::where('type', 'blocked_date')
            ->where('is_active', 1)
            ->orderBy('start_date')
            ->get()
            ->map(fn($c) => [
                'id'         => $c->id,
                'start_date' => $c->start_date->toDateString(),
                'end_date'   => $c->end_date->toDateString(),
                'reason'     => $c->reason,
                'created_at' => $c->created_at,
            ]);

        return response()->json(['success' => true, 'blocked_dates' => $dates]);
    }

    // ── ADD BLOCKED DATE ──────────────────────────────────
    // POST /api/admin/clinic/blocked-dates
    public function addBlockedDate(Request $request)
    {
        $this->requireAdmin($request);

        $today = now()->toDateString();

        $request->validate([
            'start_date' => 'required|date|after_or_equal:' . $today,
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'nullable|string|max:255',
        ]);

        $start = Carbon::parse($request->start_date);
        $end   = Carbon::parse($request->end_date);

        // Check for existing bookings in this range
        $conflictCount = Booking::whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', ['cancelled', 'no_show', 'expired', 'archived'])
            ->count();

        ClinicClosure::create([
            'type'       => 'blocked_date',
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString(),
            'reason'     => $request->reason,
            'is_active'  => 1,
            'created_by' => $request->user()->user_id,
        ]);

        return response()->json([
            'success'        => true,
            'message'        => 'Date(s) blocked successfully.',
            'conflict_count' => $conflictCount,
            'conflict_warning' => $conflictCount > 0
                ? "{$conflictCount} existing booking(s) found in this date range. Please contact those customers to cancel or reschedule."
                : null,
        ], 201);
    }

    // ── REMOVE BLOCKED DATE ───────────────────────────────
    // DELETE /api/admin/clinic/blocked-dates/{id}
    public function removeBlockedDate(Request $request, $id)
    {
        $this->requireAdmin($request);

        $closure = ClinicClosure::where('id', $id)->where('type', 'blocked_date')->first();

        if (!$closure) {
            return response()->json(['success' => false, 'message' => 'Blocked date not found.'], 404);
        }

        $closure->update(['is_active' => 0]);

        return response()->json(['success' => true, 'message' => 'Blocked date removed.']);
    }
}
