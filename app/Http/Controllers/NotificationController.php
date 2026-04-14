<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
    // ── GET NOTIFICATIONS (admin) ─────────────────────────
    // Returns the 30 most recent notifications, unread first.
    public function index()
    {
        $notifications = Notification::with('booking')
            ->orderBy('is_read', 'asc')
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($n) {
                return [
                    'notification_id' => $n->notification_id,
                    'type'            => $n->type,
                    'message'         => $n->message,
                    'is_read'         => (bool) $n->is_read,
                    'created_at'      => $n->created_at,
                    'booking_reference' => $n->booking?->booking_reference,
                ];
            });

        $unreadCount = Notification::where('is_read', 0)->count();

        return response()->json([
            'success'      => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    // ── MARK ONE AS READ ──────────────────────────────────
    public function markRead($id)
    {
        $notification = Notification::find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->update(['is_read' => 1]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }

    // ── MARK ALL AS READ ──────────────────────────────────
    public function markAllRead()
    {
        Notification::where('is_read', 0)->update(['is_read' => 1]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }
}
