<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function servicesPerformed(Request $request)
    {
        $period = $request->query('period', 'day');
        $date = $request->query('date', '');
        $month = $request->query('month', '');
        $year = $request->query('year', '');

        if (!in_array($period, ['day', 'month', 'year'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report period.',
            ], 422);
        }

        if ($period === 'day' && $date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report date.',
            ], 422);
        }

        if ($period === 'month' && $month && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report month.',
            ], 422);
        }

        if ($period === 'year' && $year && !preg_match('/^\d{4}$/', $year)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report year.',
            ], 422);
        }

        $bookings = Booking::whereNotNull('grooming_finished_at')
            ->whereNotIn('status', ['cancelled', 'no_show']);

        $this->applyPeriodFilter($bookings, 'grooming_finished_at', $period, $date, $month, $year);

        $services = BookingService::query()
            ->join('bookings', 'booking_services.booking_id', '=', 'bookings.booking_id')
            ->leftJoin('services', 'booking_services.service_id', '=', 'services.service_id')
            ->whereNotNull('bookings.grooming_finished_at')
            ->whereNotIn('bookings.status', ['cancelled', 'no_show']);

        $this->applyPeriodFilter($services, 'bookings.grooming_finished_at', $period, $date, $month, $year);

        $serviceBreakdown = (clone $services)
            ->selectRaw("COALESCE(services.service_name, 'Grooming Service') as serviceName")
            ->selectRaw('COUNT(booking_services.booking_service_id) as completedCount')
            ->groupByRaw("COALESCE(services.service_name, 'Grooming Service')")
            ->orderByDesc('completedCount')
            ->orderByRaw("COALESCE(services.service_name, 'Grooming Service')")
            ->get()
            ->map(fn ($service) => [
                'serviceName' => $service->serviceName,
                'completedCount' => (int) $service->completedCount,
            ]);

        return response()->json([
            'success' => true,
            'period' => $period,
            'date' => $date ?: null,
            'month' => $month ?: null,
            'year' => $year ?: null,
            'periodLabel' => $this->periodLabel($period, $date, $month, $year),
            'totalCompleted' => (int) (clone $services)->count('booking_services.booking_service_id'),
            'completedAppointments' => (int) $bookings->count(),
            'serviceBreakdown' => $serviceBreakdown->values(),
        ]);
    }

    public function customerActivity(Request $request)
    {
        $period = $request->query('period', 'day');
        $date = $request->query('date', '');
        $month = $request->query('month', '');
        $year = $request->query('year', '');

        if (!in_array($period, ['day', 'month', 'year'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report period.',
            ], 422);
        }

        if ($period === 'day' && $date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report date.',
            ], 422);
        }

        if ($period === 'month' && $month && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report month.',
            ], 422);
        }

        if ($period === 'year' && $year && !preg_match('/^\d{4}$/', $year)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid report year.',
            ], 422);
        }

        $visits = Booking::whereNotNull('grooming_finished_at')
            ->whereNotIn('status', ['cancelled', 'no_show']);

        $this->applyPeriodFilter($visits, 'grooming_finished_at', $period, $date, $month, $year);

        $topCustomer = (clone $visits)
            ->join('users', 'bookings.user_id', '=', 'users.user_id')
            ->select('bookings.user_id', 'users.first_name', 'users.last_name')
            ->selectRaw('COUNT(bookings.booking_id) as visitCount')
            ->groupBy('bookings.user_id', 'users.first_name', 'users.last_name')
            ->orderByDesc('visitCount')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->first();

        $allVisits = Booking::whereNotNull('grooming_finished_at')
            ->whereNotIn('status', ['cancelled', 'no_show']);

        $allCustomers = (clone $allVisits)
            ->join('users', 'bookings.user_id', '=', 'users.user_id')
            ->select('bookings.user_id', 'users.first_name', 'users.last_name', 'users.email', 'users.phone')
            ->selectRaw('COUNT(bookings.booking_id) as visitCount')
            ->selectRaw('MIN(bookings.grooming_finished_at) as firstVisitAt')
            ->selectRaw('MAX(bookings.grooming_finished_at) as lastVisitAt')
            ->groupBy('bookings.user_id', 'users.first_name', 'users.last_name', 'users.email', 'users.phone')
            ->orderByDesc('visitCount')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->get()
            ->map(fn ($customer) => [
                'id' => (int) $customer->user_id,
                'customerName' => trim($customer->first_name . ' ' . $customer->last_name),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'visitCount' => (int) $customer->visitCount,
                'firstVisitAt' => $customer->firstVisitAt,
                'lastVisitAt' => $customer->lastVisitAt,
            ]);

        $periodCustomerSummaries = (clone $visits)
            ->select('bookings.user_id')
            ->selectRaw('COUNT(bookings.booking_id) as visitCount')
            ->selectRaw('MAX(bookings.grooming_finished_at) as lastVisitAt')
            ->groupBy('bookings.user_id')
            ->get()
            ->keyBy('user_id');

        $hasSelectedPeriodValue = ($period === 'day' && $date)
            || ($period === 'month' && $month)
            || ($period === 'year' && $year);

        $newCustomers = $allCustomers
            ->filter(fn ($customer) => $this->dateMatchesPeriod($customer['firstVisitAt'], $period, $date, $month, $year))
            ->map(function ($customer) use ($periodCustomerSummaries) {
                $periodSummary = $periodCustomerSummaries->get($customer['id']);

                return array_merge($customer, [
                    'visitCount' => $periodSummary ? (int) $periodSummary->visitCount : 0,
                    'lastVisitAt' => $periodSummary->lastVisitAt ?? $customer['lastVisitAt'],
                ]);
            })
            ->sortByDesc('firstVisitAt')
            ->values();

        $returningCustomers = $allCustomers
            ->filter(function ($customer) use ($periodCustomerSummaries, $hasSelectedPeriodValue, $period, $date, $month, $year) {
                if (!$periodCustomerSummaries->has($customer['id'])) {
                    return false;
                }

                if (!$hasSelectedPeriodValue) {
                    return $customer['visitCount'] > 1;
                }

                return !$this->dateMatchesPeriod($customer['firstVisitAt'], $period, $date, $month, $year);
            })
            ->map(function ($customer) use ($periodCustomerSummaries) {
                $periodSummary = $periodCustomerSummaries->get($customer['id']);

                return array_merge($customer, [
                    'visitCount' => $periodSummary ? (int) $periodSummary->visitCount : 0,
                    'lastVisitAt' => $periodSummary->lastVisitAt ?? $customer['lastVisitAt'],
                ]);
            })
            ->sortByDesc('lastVisitAt')
            ->values();

        $scheduledBookings = Booking::whereNotIn('status', ['cancelled']);
        $this->applyPeriodFilter($scheduledBookings, 'booking_date', $period, $date, $month, $year);

        $scheduledByCustomer = (clone $scheduledBookings)
            ->select('user_id')
            ->selectRaw('COUNT(booking_id) as scheduledBookings')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $scheduledCount = (int) (clone $scheduledBookings)->count();
        $noShows = Booking::where('status', 'no_show');
        $this->applyPeriodFilter($noShows, 'booking_date', $period, $date, $month, $year);

        $noShowCustomers = (clone $noShows)
            ->join('users', 'bookings.user_id', '=', 'users.user_id')
            ->select('bookings.user_id', 'users.first_name', 'users.last_name', 'users.email', 'users.phone')
            ->selectRaw('COUNT(bookings.booking_id) as noShowCount')
            ->selectRaw('MIN(bookings.booking_date) as firstNoShowDate')
            ->selectRaw('MAX(bookings.booking_date) as latestNoShowDate')
            ->groupBy('bookings.user_id', 'users.first_name', 'users.last_name', 'users.email', 'users.phone')
            ->orderByDesc('noShowCount')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->get()
            ->map(function ($customer) use ($scheduledByCustomer) {
                $noShowCount = (int) $customer->noShowCount;
                $scheduledCount = (int) ($scheduledByCustomer->get($customer->user_id)->scheduledBookings ?? $noShowCount);

                return [
                    'id' => (int) $customer->user_id,
                    'customerName' => trim($customer->first_name . ' ' . $customer->last_name),
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'noShowCount' => $noShowCount,
                    'noShowRate' => $scheduledCount > 0 ? round(($noShowCount / $scheduledCount) * 100, 1) : 0,
                    'scheduledBookings' => $scheduledCount,
                    'firstNoShowDate' => $customer->firstNoShowDate,
                    'latestNoShowDate' => $customer->latestNoShowDate,
                ];
            });

        $noShowCount = (int) $noShowCustomers->sum('noShowCount');
        $noShowRate = $scheduledCount > 0
            ? round(($noShowCount / $scheduledCount) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'period' => $period,
            'date' => $date ?: null,
            'month' => $month ?: null,
            'year' => $year ?: null,
            'periodLabel' => $this->periodLabel($period, $date, $month, $year),
            'totalUniqueCustomers' => (int) (clone $visits)->distinct('bookings.user_id')->count('bookings.user_id'),
            'completedVisits' => (int) (clone $visits)->count('bookings.booking_id'),
            'topCustomer' => $topCustomer ? [
                'id' => (int) $topCustomer->user_id,
                'customerName' => trim($topCustomer->first_name . ' ' . $topCustomer->last_name),
                'visitCount' => (int) $topCustomer->visitCount,
            ] : null,
            'allCustomers' => $allCustomers->values(),
            'newCustomers' => $newCustomers,
            'returningCustomers' => $returningCustomers,
            'noShowCount' => $noShowCount,
            'noShowRate' => $noShowRate,
            'scheduledBookings' => $scheduledCount,
            'noShowCustomers' => $noShowCustomers->values(),
        ]);
    }

    private function applyPeriodFilter($query, string $column, string $period, string $date, string $month, string $year): void
    {
        if ($period === 'day' && $date) {
            $query->whereDate($column, $date);
            return;
        }

        if ($period === 'month' && $month) {
            [$selectedYear, $selectedMonth] = explode('-', $month);
            $query->whereYear($column, (int) $selectedYear)
                ->whereMonth($column, (int) $selectedMonth);
            return;
        }

        if ($period === 'year' && $year) {
            $query->whereYear($column, (int) $year);
        }
    }

    private function dateMatchesPeriod(?string $value, string $period, string $date, string $month, string $year): bool
    {
        if (!$value) {
            return false;
        }

        $selectedDate = Carbon::parse($value);

        if ($period === 'day' && $date) {
            return $selectedDate->toDateString() === $date;
        }

        if ($period === 'month' && $month) {
            return $selectedDate->format('Y-m') === $month;
        }

        if ($period === 'year' && $year) {
            return $selectedDate->format('Y') === $year;
        }

        return true;
    }

    private function periodLabel(string $period, string $date, string $month, string $year): string
    {
        if ($period === 'day' && $date) {
            return Carbon::parse($date)->format('M j, Y');
        }

        if ($period === 'month' && $month) {
            return Carbon::parse($month . '-01')->format('F Y');
        }

        if ($period === 'year' && $year) {
            return $year;
        }

        return 'All dates';
    }
}
