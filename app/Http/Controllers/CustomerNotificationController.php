<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustomerNotification;

class CustomerNotificationController extends Controller
{
    // GET /customer/notifications
    public function index(Request $request)
    {
        $userId = $request->user()->user_id;

        $notifications = CustomerNotification::where('user_id', $userId)
            ->orderBy('is_read', 'asc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(30)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'message'    => $n->message,
                'is_read'    => (bool) $n->is_read,
                'created_at' => $n->created_at?->toDateTimeString(),
                'booking_id' => $n->booking_id,
            ]);

        $unreadCount = CustomerNotification::where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        // Check for any unread ready_for_pickup notification
        $pickupNotif = CustomerNotification::where('user_id', $userId)
            ->where('type', 'ready_for_pickup')
            ->where('is_read', 0)
            ->with('booking')
            ->latest('created_at')
            ->first();

        return response()->json([
            'success'         => true,
            'unread_count'    => $unreadCount,
            'notifications'   => $notifications,
            'pickup_alert'    => $pickupNotif ? [
                'id'         => $pickupNotif->id,
                'message'    => $pickupNotif->message,
                'booking_id' => $pickupNotif->booking_id,
            ] : null,
        ]);
    }

    // PATCH /customer/notifications/{id}/read
    public function markRead(Request $request, $id)
    {
        $notif = CustomerNotification::where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (!$notif) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $notif->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }

    // PATCH /customer/notifications/read-all
    public function markAllRead(Request $request)
    {
        CustomerNotification::where('user_id', $request->user()->user_id)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }
}
