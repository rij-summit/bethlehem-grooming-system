<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Notification;
use App\Services\GroomingBookingWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileGroomingBookingWorkflows extends Command
{
    protected $signature = 'bookings:reconcile-workflows';

    protected $description = 'Repairs safe grooming parent-status drift and reports bookings that still need staff action.';

    public function handle(GroomingBookingWorkflowService $workflow): int
    {
        $checked = 0;
        $corrected = 0;
        $actionRequired = 0;

        Booking::query()
            ->whereIn('status', ['checked_in', 'in_progress', 'for_payment'])
            ->orderBy('booking_id')
            ->chunkById(100, function ($bookings) use (
                $workflow,
                &$checked,
                &$corrected,
                &$actionRequired,
            ): void {
                foreach ($bookings as $booking) {
                    $result = DB::transaction(function () use ($booking, $workflow): array {
                        $locked = Booking::query()
                            ->whereKey($booking->booking_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        return $workflow->reconcile($locked, true);
                    }, 3);

                    $checked++;
                    $corrected += (int) ($result['status_changed'] ?? false);

                    if (! ($result['action_required'] ?? false)) {
                        continue;
                    }

                    $actionRequired++;
                    $this->recordActionRequiredNotification($booking);
                }
            }, 'booking_id');

        $this->info(
            "Checked {$checked} active grooming booking(s); "
            ."corrected {$corrected}; action required {$actionRequired}.",
        );

        return self::SUCCESS;
    }

    private function recordActionRequiredNotification(Booking $booking): void
    {
        if (
            ! Schema::hasTable('notifications')
            || ! $booking->booking_date
            || $booking->booking_date >= now()->toDateString()
        ) {
            return;
        }

        $message = "Action required for {$booking->booking_reference}: complete the stopped-grooming payment review before payment and pickup.";

        Notification::firstOrCreate(
            [
                'type' => 'payment_due',
                'booking_id' => $booking->booking_id,
                'message' => $message,
            ],
            [
                'is_read' => false,
                'created_at' => now(),
            ],
        );
    }
}
