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
            ->with(['booking.bookingPets.pet', 'booking.timeWindow'])
            ->orderBy('is_read', 'asc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($n) {
                $petNames = $this->notificationPetNames($n);

                return [
                    'id'              => $n->id,
                    'type'            => $n->type,
                    'message'         => $this->formatNotificationMessage($n->message),
                    'display_message' => $this->formatCustomerNotificationMessage($n, $petNames),
                    'pet_names'       => $petNames,
                    'is_read'         => (bool) $n->is_read,
                    'created_at'      => $n->created_at?->toDateTimeString(),
                    'booking_id'      => $n->booking_id,
                ];
            });

        $unreadCount = CustomerNotification::where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        // Check for any unread ready_for_pickup notification
        $pickupNotif = CustomerNotification::where('user_id', $userId)
            ->where('type', 'ready_for_pickup')
            ->where('is_read', 0)
            ->with(['booking.bookingPets.pet', 'booking.timeWindow'])
            ->latest('created_at')
            ->first();

        $pickupPetNames = $pickupNotif ? $this->notificationPetNames($pickupNotif) : [];

        return response()->json([
            'success'         => true,
            'unread_count'    => $unreadCount,
            'notifications'   => $notifications,
            'pickup_alert'    => $pickupNotif ? [
                'id'              => $pickupNotif->id,
                'message'         => $this->formatNotificationMessage($pickupNotif->message),
                'display_message' => $this->formatCustomerNotificationMessage($pickupNotif, $pickupPetNames),
                'pet_names'       => $pickupPetNames,
                'booking_id'      => $pickupNotif->booking_id,
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

    private function notificationPetNames(CustomerNotification $notification): array
    {
        $petNames = ($notification->booking?->bookingPets ?? collect())
            ->map(fn($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!in_array($notification->type, ['grooming_started', 'grooming_finished'], true)) {
            return $petNames;
        }

        $matchedPetNames = $this->petNamesMentionedInMessage($notification->message, $petNames);

        return $matchedPetNames ?: $petNames;
    }

    private function formatCustomerNotificationMessage(CustomerNotification $notification, array $petNames): string
    {
        if (empty($petNames)) {
            return match ($notification->type) {
                'grooming_started' => "Great news! Your pet has Started Grooming. We'll let you know as soon as they're ready for pickup!",
                'grooming_finished' => "Your pet is Finished with grooming. We'll keep you updated on the rest of the appointment.",
                'ready_for_pickup' => 'Your pet is now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.',
                default => $this->formatNotificationMessage($notification->message),
            };
        }

        $subject = $this->formatNameList($petNames);
        $isPlural = count($petNames) > 1;
        $timeLabel = $notification->booking?->timeWindow?->window_label;

        return match ($notification->type) {
            'grooming_started' => "Great news! {$subject} " . ($isPlural ? 'have' : 'has') . " Started Grooming. We'll let you know as soon as they're ready for pickup!",
            'grooming_finished' => "{$subject} " . ($isPlural ? 'are' : 'is') . " Finished with grooming. We'll keep you updated on the rest of the appointment.",
            'ready_for_pickup' => 'Your ' . ($isPlural ? 'pets are' : 'pet is') . ' now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.',
            'pickup_reminder'  => "Reminder: {$subject} " . ($isPlural ? 'are' : 'is') . " still waiting to be picked up at the clinic. Please come at your earliest convenience!",
            'picked_up'        => "{$subject} " . ($isPlural ? 'have' : 'has') . " been released. Thank you for visiting Bethlehem Animal Clinic!",
            'reminder_24h'     => $timeLabel
                ? "Reminder: {$subject}'s grooming appointment is tomorrow at {$timeLabel}. Please don't forget!"
                : $this->formatNotificationMessage($notification->message),
            'reminder_3h'      => $timeLabel
                ? "Heads up! {$subject}'s grooming appointment is in about 3 hours at {$timeLabel}. See you soon!"
                : $this->formatNotificationMessage($notification->message),
            default            => $this->formatNotificationMessage($notification->message),
        };
    }

    private function petNamesMentionedInMessage(?string $message, array $petNames): array
    {
        $text = (string) $message;

        return array_values(array_filter($petNames, function ($petName) use ($text) {
            $name = trim((string) $petName);

            if ($name === '') {
                return false;
            }

            return preg_match('/(^|[^\pL\pN])' . preg_quote($name, '/') . '($|[^\pL\pN])/iu', $text) === 1;
        }));
    }

    private function formatNotificationMessage(?string $message): string
    {
        $text = (string) $message;

        $text = preg_replace(
            '/\bNew booking\s+(BAC-[A-Za-z0-9-]+)/i',
            'New Pre-registration $1',
            $text
        );

        $text = preg_replace(
            '/\bBooking\s+(BAC-[A-Za-z0-9-]+)/i',
            'Pre-registration $1',
            $text
        );

        return $text;
    }

    private function formatNameList(array $names): string
    {
        $cleanNames = array_values(array_filter(array_map(
            fn($name) => trim((string) $name),
            $names,
        )));

        if (count($cleanNames) <= 1) {
            return $cleanNames[0] ?? 'your pet';
        }

        if (count($cleanNames) === 2) {
            return $cleanNames[0] . ' and ' . $cleanNames[1];
        }

        return implode(', ', array_slice($cleanNames, 0, -1)) . ', and ' . end($cleanNames);
    }
}
