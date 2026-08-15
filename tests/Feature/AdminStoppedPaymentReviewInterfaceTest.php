<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminStoppedPaymentReviewInterfaceTest extends TestCase
{
    private string $appointmentsPage;

    private string $dashboardComponent;

    private string $reviewComponent;

    private string $apiLayer;

    private string $customStyles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appointmentsPage = file_get_contents(
            base_path('pages/admin/appointments.html'),
        );
        $this->dashboardComponent = file_get_contents(
            base_path('scripts/components/admin-dashboard.js'),
        );
        $this->reviewComponent = file_get_contents(
            base_path('scripts/components/admin-stopped-payment-review.js'),
        );
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
        $this->customStyles = file_get_contents(base_path('css/custom.css'));
    }

    public function test_central_api_helpers_use_the_existing_get_and_post_routes(): void
    {
        foreach ([
            'getAdminStoppedPaymentReview',
            'createAdminStoppedPaymentReview',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString(
            '/stopped-payment-review`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            '/medical-concerns/${encodeURIComponent(concernId)}/stopped-payment-review`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            'API.getAdminStoppedPaymentReview(',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'API.createAdminStoppedPaymentReview(',
            $this->reviewComponent,
        );
        $this->assertStringNotContainsString('fetch(', $this->reviewComponent);
    }

    public function test_review_component_is_loaded_and_scoped_to_each_exact_stopped_pet_card(): void
    {
        $reviewScriptPosition = strpos(
            $this->appointmentsPage,
            'scripts/components/admin-stopped-payment-review.js',
        );
        $dashboardScriptPosition = strpos(
            $this->appointmentsPage,
            'scripts/components/admin-dashboard.js',
        );

        $this->assertNotFalse($reviewScriptPosition);
        $this->assertNotFalse($dashboardScriptPosition);
        $this->assertLessThan($dashboardScriptPosition, $reviewScriptPosition);
        $this->assertStringContainsString(
            '...adminStoppedPaymentReviewState()',
            $this->dashboardComponent,
        );
        $this->assertSame(
            2,
            substr_count(
                $this->appointmentsPage,
                '@click="openStoppedPaymentReview(booking, pet, $event.currentTarget)"',
            ),
        );
        $this->assertSame(
            2,
            substr_count(
                $this->appointmentsPage,
                'x-show="isStoppedPaymentReviewPet(pet)"',
            ),
        );
        $this->assertStringContainsString(
            'return bookingId && bookingPetId ? `${bookingId}:${bookingPetId}` : "";',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'const concernId = review?.concern_id;',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'await this.refreshStoppedPaymentReviewStatuses();',
            $this->dashboardComponent,
        );
    }

    public function test_status_loading_pending_completed_ineligible_error_and_retry_states_are_present(): void
    {
        foreach ([
            'Loading payment review status...',
            'Payment review required',
            'Payment Review Completed',
            'Payment review is not currently available',
            'Payment review could not be loaded',
            'Grooming pet record unavailable',
            '@click="loadStoppedPaymentReview()"',
            'review_creation_blocked_reason',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'loading: "Loading payment review..."',
            'completed: "Payment review completed"',
            'ineligible: "Payment review unavailable"',
            'error: "Payment review could not be loaded"',
            'pending: "Payment review required"',
        ] as $status) {
            $this->assertStringContainsString($status, $this->reviewComponent);
        }
    }

    public function test_pending_dialog_uses_server_service_snapshots_and_selected_pet_context(): void
    {
        foreach ([
            'This review applies only to this pet.',
            'Completing this review may move the owner booking to For Payment when every pet is finished or reviewed.',
            'Saved services and add-ons',
            'Saved booking prices are used. Invalid legacy zero snapshots use the configured size or service rate without overwriting the saved row.',
            'Original pet subtotal',
            'line.booking_service_id',
            'line.line_type',
            'line.price_at_booking',
            'line.price_source',
            'Configured rate recovered',
            'concern_public_id',
            'applied_stop_grooming_at',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        $payloadBuilder = $this->sourceBetween(
            $this->reviewComponent,
            'buildStoppedPaymentReviewPayload() {',
            'openStoppedPaymentReviewConfirmation() {',
        );
        foreach ([
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'concern_id',
            'original_pet_subtotal',
            'reviewed_by_user_id',
            'reviewed_by_name',
            'reviewed_at',
            'adjustment',
            'payment_status',
            'booking_status',
        ] as $serverManagedField) {
            $this->assertStringNotContainsString($serverManagedField, $payloadBuilder);
        }

        $this->assertStringContainsString(
            'payload.final_pet_charge =',
            $payloadBuilder,
        );
        $this->assertStringContainsString(
            'if (payload.decision === "partial_charge")',
            $payloadBuilder,
        );
    }

    public function test_decisions_amounts_and_explanations_mirror_backend_validation(): void
    {
        foreach ([
            'value: "full_charge"',
            'value: "partial_charge"',
            'value: "no_charge"',
            'decision: ""',
            'No option is selected by default.',
            'Select Full Charge, Partial Charge, or No Charge.',
            'A zero-subtotal pet must use No Charge.',
            'Final charge is required for Partial Charge.',
            'Partial Charge must be greater than zero.',
            'Partial Charge must be less than the original pet subtotal.',
            'no more than two decimal places',
            'Internal staff reason is required.',
            'Customer-friendly explanation is required.',
            'must not exceed 5,000 characters',
            'must not exceed 2,000 characters',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->appointmentsPage.$this->reviewComponent,
            );
        }

        $this->assertStringContainsString('maxlength="5000"', $this->appointmentsPage);
        $this->assertStringContainsString('maxlength="2000"', $this->appointmentsPage);
        $this->assertStringNotContainsString(
            'customer_explanation = this.stoppedPaymentReviewForm.internal_reason',
            $this->reviewComponent,
        );
    }

    public function test_confirmation_submission_retry_and_conflict_handling_preserve_immutability(): void
    {
        foreach ([
            'Complete Permanent Payment Review?',
            'This completed review will be saved as a permanent record and cannot be edited or deleted.',
            'Customer-friendly explanation',
            'Confirm and Complete',
            '@click="submitStoppedPaymentReview()"',
            ':disabled="stoppedPaymentReviewConfirmation.busy"',
            'error.status === 409',
            'The existing completed payment review was recovered.',
            'The completed review has been reloaded.',
            'This is a permanent, immutable record. It cannot be edited or deleted.',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->appointmentsPage.$this->reviewComponent,
            );
        }

        foreach ([
            'updateAdminStoppedPaymentReview',
            'deleteAdminStoppedPaymentReview',
            'replaceAdminStoppedPaymentReview',
        ] as $forbiddenHelper) {
            $this->assertStringNotContainsString(
                $forbiddenHelper,
                $this->apiLayer.$this->reviewComponent,
            );
        }
    }

    public function test_completed_display_is_read_only_and_refreshes_integrated_payment_readiness(): void
    {
        foreach ([
            'Decision',
            'Original pet subtotal',
            'Final pet charge',
            'Adjustment',
            'Internal staff reason',
            'Customer-friendly explanation',
            'Reviewed by',
            'Reviewed at',
            'Saved service breakdown',
            'Payment review is complete. When every pet is finished or reviewed, this booking will move to For Payment automatically.',
            'Completing this review does not collect payment or create a transaction.',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        $submission = $this->sourceBetween(
            $this->reviewComponent,
            'async submitStoppedPaymentReview() {',
            'applyStoppedPaymentReviewBackendErrors(error) {',
        );
        $this->assertStringContainsString('loadAdminBookings', $submission);
        foreach ([
            'processPayment',
            'payNow',
            'releaseBooking',
            'markPickedUp',
            'adminMarkDone',
            'adminMarkPetDone',
        ] as $unrelatedMutation) {
            $this->assertStringNotContainsString($unrelatedMutation, $submission);
        }
    }

    public function test_multi_pet_isolation_uses_independent_cache_keys_and_never_mutates_siblings(): void
    {
        $this->assertStringContainsString(
            'contexts.push({ booking, pet });',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'this.loadStoppedPaymentReview(booking, pet, { updateModal: false })',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'API.createAdminStoppedPaymentReview(',
            $this->reviewComponent,
        );
        $this->assertStringNotContainsString(
            'booking.pets.map',
            $this->reviewComponent,
        );

        foreach ([
            'adminStartPetGrooming',
            'adminMarkPetDone',
            'adminStartGrooming',
            'adminMarkDone',
            'processPayment',
            'releaseBooking',
            'adminUpdateGroomersOnDuty',
            'createAdminBookingPetMedicalConcern',
        ] as $unrelatedMutation) {
            $this->assertStringNotContainsString(
                "API.{$unrelatedMutation}",
                $this->reviewComponent,
            );
        }
    }

    public function test_dialog_has_responsive_and_accessible_controls_and_focus_management(): void
    {
        foreach ([
            'aria-modal="true"',
            'role="alert"',
            'aria-live="polite"',
            'type="radio"',
            'x-ref="stoppedPaymentReviewDialog"',
            'x-ref="stoppedPaymentReviewConfirmationDialog"',
            'x-ref="stoppedPaymentReviewValidationSummary"',
            'aria-describedby="stopped-payment-review-final-charge-help stopped-payment-review-final-charge-error"',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            '.admin-stopped-payment-review-dialog',
            '.admin-stopped-payment-review-decision-grid',
            '.admin-stopped-payment-review-validation:focus',
            '.admin-stopped-payment-review-decision:has(input:focus-visible)',
            '@media (max-width: 767px)',
        ] as $selector) {
            $this->assertStringContainsString($selector, $this->customStyles);
        }

        $this->assertStringContainsString(
            'this.$refs.stoppedPaymentReviewDialog?.focus()',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'this.$refs.stoppedPaymentReviewConfirmationDialog?.focus()',
            $this->reviewComponent,
        );
        $this->assertStringContainsString(
            'returnFocus?.focus?.()',
            $this->reviewComponent,
        );
    }

    public function test_dialog_uses_the_shared_admin_typography_hierarchy(): void
    {
        foreach ([
            '/\.admin-stopped-payment-review-title\s*\{[^}]*font-size:\s*1\.125rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-stopped-payment-review-section-heading h4\s*\{[^}]*font-size:\s*1\.125rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-stopped-payment-review-context strong\s*\{[^}]*font-size:\s*1rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-stopped-payment-review-service-line strong\s*\{[^}]*font-size:\s*1rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-stopped-payment-review-context\s*\{[^}]*font-size:\s*0\.875rem;[^}]*font-weight:\s*500;/s',
            '/\.admin-stopped-payment-review-field-help\s*\{[^}]*font-size:\s*0\.875rem;[^}]*font-weight:\s*500;/s',
            '/\.admin-stopped-payment-review-kicker\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;[^}]*text-transform:\s*uppercase;/s',
            '/\.admin-stopped-payment-review-decisions legend,\s*\.admin-stopped-payment-review-field label\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;[^}]*text-transform:\s*uppercase;/s',
        ] as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $this->customStyles);
        }

        $paymentReviewStyles = $this->sourceBetween(
            $this->customStyles,
            '/* Stopped-grooming payment review */',
            '@media (max-width: 767px)',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/font-weight:\s*(?:800|900);/',
            $paymentReviewStyles,
        );
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
