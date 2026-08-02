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
