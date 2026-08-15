<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReviewedStoppedPaymentInterfaceTest extends TestCase
{
    private string $dashboard;

    private string $appointments;

    private string $clientDashboard;

    private string $petDetails;

    private string $transactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $this->appointments = file_get_contents(base_path('pages/admin/appointments.html'));
        $this->clientDashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));
        $this->petDetails = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->transactions = file_get_contents(base_path('pages/admin/transactions.html'));
    }

    public function test_payment_modal_uses_server_reviewed_pet_context_and_preserves_original_lines(): void
    {
        foreach ([
            'booking?.paymentSummary ?? booking?.payment_summary',
            'serverPet?.payment_kind === "stopped_reviewed"',
            'serverPet.service_breakdown',
            'serverPet.final_pet_charge',
            'serverPet.adjustment',
            'serverPet.customer_explanation',
            'if (pet?.isStoppedReviewed) return;',
            'Stopped-pet service prices are historical',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->dashboard.file_get_contents(
                base_path('app/Http/Controllers/PaymentController.php'),
            ));
        }

        foreach ([
            'Payment review completed',
            'Original subtotal',
            'Reviewed charge',
            'Customer explanation:',
            'Grooming stopped',
        ] as $label) {
            $this->assertStringContainsString($label, $this->appointments);
        }
    }

    public function test_zero_total_and_pay_now_restrictions_are_visible_and_accessible(): void
    {
        foreach ([
            'No payment required',
            'This ₱0.00 completion will be recorded without cash tender or change.',
            'bookingHasPayNowBlockedPet(booking)',
            'Pay Now unavailable',
            'Pay Now is unavailable while a pet is paused or stopped.',
            ':disabled="!canSubmitPayment"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->appointments.$this->dashboard);
        }
    }

    public function test_action_required_booking_stays_in_queued_with_muted_card_styling(): void
    {
        $styles = file_get_contents(base_path('css/custom.css'));
        $queuedSection = $this->sourceBetween(
            $this->appointments,
            '<!-- Queued -->',
            '<!-- In Progress -->',
        );

        foreach ([
            'admin-queued-booking-card',
            'bookingRequiresAction(booking)',
            "'Action required'",
            'actionRequiredReason',
            'openStoppedPaymentReview(booking, pet',
        ] as $expected) {
            $this->assertStringContainsString($expected, $queuedSection);
        }

        $this->assertStringContainsString(
            'this.setTab?.("to-be-picked-up")',
            file_get_contents(base_path('scripts/components/admin-stopped-payment-review.js')),
        );

        $this->assertStringContainsString('.admin-queued-booking-card.is-action-required', $styles);
        $this->assertStringContainsString('filter: grayscale(1)', $styles);
        $this->assertStringNotContainsString('uppercase', $this->sourceBetween(
            $queuedSection,
            'bookingRequiresAction(booking) ? \'is-action-required\'',
            '<!-- Queue ETA badge -->',
        ));
    }

    public function test_no_charge_confirmation_explains_the_zero_total_shortcut(): void
    {
        foreach ([
            'complete booking total is exactly \\u20B10.00',
            'a &#8369;0.00 payment will be recorded',
            'Confirm No charge',
            'move to To Be Picked Up',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->appointments);
        }

        $reviewScript = file_get_contents(
            base_path('scripts/components/admin-stopped-payment-review.js'),
        );
        $this->assertStringContainsString('booking_status === "released"', $reviewScript);
        $this->assertStringContainsString('this.setTab?.("to-be-picked-up")', $reviewScript);
    }

    public function test_receipt_uses_persisted_server_summary_and_excludes_private_review_fields(): void
    {
        $submission = $this->sourceBetween(
            $this->dashboard,
            'async submitPayment() {',
            'closeReceiptModal() {',
        );
        $this->assertStringContainsString('res?.payment_summary', $submission);
        $this->assertStringContainsString('buildServerPaymentReceiptPets', $submission);
        $receipt = $this->sourceBetween(
            $this->appointments,
            '<!-- Receipt Modal -->',
            '<!-- Shared secondary action confirmation modal -->',
        );
        $this->assertStringContainsString('Ready for pickup', $receipt);
        $this->assertStringContainsString('Payment Review Completed', $receipt);
        $this->assertStringNotContainsString('internal_reason', $submission.$receipt);
        $this->assertStringNotContainsString('safety_override_reason', $submission.$receipt);
    }

    public function test_staff_transactions_and_customer_grooming_views_show_safe_review_details(): void
    {
        foreach ([
            'tx.paymentSummary?.pets',
            'pet.final_pet_charge',
            'pet.review_decision_label',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->transactions);
        }

        foreach ([
            'payment_summary?.pets',
            'Payment Review Completed',
            'customer_explanation',
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $this->clientDashboard.$this->petDetails,
            );
        }

        foreach (['internal_reason', 'reviewed_by_user_id', 'safety_override_reason'] as $privateField) {
            $this->assertStringNotContainsString(
                $privateField,
                $this->clientDashboard.$this->petDetails,
            );
        }
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        $this->assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
