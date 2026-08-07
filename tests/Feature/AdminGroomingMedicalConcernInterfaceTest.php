<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminGroomingMedicalConcernInterfaceTest extends TestCase
{
    private string $appointmentsPage;

    private string $dashboardComponent;

    private string $concernComponent;

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
        $this->concernComponent = file_get_contents(
            base_path('scripts/components/admin-grooming-concerns.js'),
        );
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
        $this->customStyles = file_get_contents(base_path('css/custom.css'));
    }

    public function test_central_api_layer_exposes_all_nine_booking_pet_concern_operations(): void
    {
        foreach ([
            'getAdminBookingPetMedicalConcerns',
            'createAdminBookingPetMedicalConcern',
            'getAdminBookingPetMedicalConcern',
            'updateAdminBookingPetMedicalConcern',
            'cancelAdminBookingPetMedicalConcern',
            'resolveAdminBookingPetMedicalConcern',
            'notifyCustomerAboutAdminBookingPetMedicalConcern',
            'applyAdminBookingPetMedicalConcernAction',
            'resumeAdminBookingPetGrooming',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString(
            '/medical-concerns`',
            $this->apiLayer,
        );
        $this->assertStringContainsString('/cancel`', $this->apiLayer);
        $this->assertStringContainsString('/resolve`', $this->apiLayer);
        $this->assertStringContainsString('/notify-customer`', $this->apiLayer);
        $this->assertStringContainsString('/apply-recommended-action`', $this->apiLayer);
        $this->assertStringContainsString('/resume-grooming`', $this->apiLayer);
        $this->assertStringNotContainsString('fetch(', $this->concernComponent);
    }

    public function test_concern_actions_and_indicators_are_scoped_to_each_pet_card(): void
    {
        $this->assertSame(
            4,
            substr_count(
                $this->appointmentsPage,
                '@click="openMedicalConcerns(booking, pet)"',
            ),
        );
        $this->assertSame(
            0,
            substr_count(
                $this->appointmentsPage,
                '@click="openReportMedicalConcern(booking, pet)"',
            ),
        );
        $this->assertStringNotContainsString(
            'admin-queued-pet-report-concern',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            '@click="openCreateMedicalConcern()"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'Report Medical Concern',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'medicalConcernIndicatorLabel(booking, pet)',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'const bookingPetId = this.bookingPetIdentifier(pet);',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'return bookingId && bookingPetId ? `${bookingId}:${bookingPetId}` : "";',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'API.getAdminBookingPetMedicalConcerns(',
            $this->concernComponent,
        );
    }

    public function test_history_has_loading_empty_error_retry_and_complete_lifecycle_states(): void
    {
        foreach ([
            'Loading medical concern history...',
            'No medical concerns have been reported for this pet.',
            'Medical concern history could not be loaded',
            '@click="loadMedicalConcerns()"',
            'Grooming pet record unavailable',
            'Internal staff observation',
            'Customer-visible message',
            'Acknowledgment required',
            'Consent required',
            'Customer response exists',
            'Resolution information',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'open: "Open"',
            'awaiting_customer: "Awaiting customer"',
            'referred_to_clinic: "Referred to clinic"',
            'under_clinic_review: "Under clinic review"',
            'resolved: "Resolved"',
            'cancelled: "Cancelled"',
        ] as $status) {
            $this->assertStringContainsString($status, $this->concernComponent);
        }

        foreach (['Low', 'Moderate', 'Urgent'] as $severity) {
            $this->assertStringContainsString("label: \"{$severity}\"", $this->concernComponent);
        }
    }

    public function test_concern_modal_uses_the_admin_typography_hierarchy_without_a_shell_outline(): void
    {
        foreach ([
            '/\.admin-medical-concern-dialog\s*\{[^}]*border:\s*0;[^}]*outline:\s*none;/s',
            '/\.admin-medical-concern-title\s*\{[^}]*font-size:\s*1\.125rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-medical-concern-category\s*\{[^}]*font-size:\s*1rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-medical-concern-meta\s*\{[^}]*font-size:\s*0\.875rem;[^}]*font-weight:\s*500;/s',
            '/\.admin-medical-concern-kicker\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;/s',
        ] as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $this->customStyles);
        }

        $this->assertStringContainsString(
            'class="text-lg font-semibold text-[#1f3850]">Concern records</p>',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'class="text-sm font-medium text-slate-500"',
            $this->appointmentsPage,
        );

        foreach ([
            '/\.admin-medical-concern-card-summary span,\s*\.admin-medical-concern-detail-grid span\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;[^}]*letter-spacing:\s*0;[^}]*text-transform:\s*none;/s',
            '/\.admin-medical-concern-text-grid h5,\s*\.admin-medical-concern-resolution h5\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;[^}]*letter-spacing:\s*0;[^}]*text-transform:\s*none;/s',
        ] as $historyLabelPattern) {
            $this->assertMatchesRegularExpression(
                $historyLabelPattern,
                $this->customStyles,
            );
        }
    }

    public function test_expanded_pet_cards_use_the_admin_typography_hierarchy(): void
    {
        foreach ([
            '/\.admin-queued-pets-title\s*\{[^}]*font-size:\s*1\.125rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-queued-pet-queue\s*\{[^}]*font-size:\s*1\.125rem;[^}]*font-weight:\s*700;/s',
            '/\.admin-queued-pet-information h5\s*\{[^}]*font-size:\s*1rem;[^}]*font-weight:\s*700;/s',
            '/\.admin-queued-pet-basic\s*\{[^}]*font-size:\s*0\.875rem;[^}]*font-weight:\s*500;/s',
            '/\.admin-queued-pet-services\s*\{[^}]*font-size:\s*0\.875rem;[^}]*font-weight:\s*600;/s',
            '/\.admin-queued-pet-notes span\s*\{[^}]*font-size:\s*0\.75rem;[^}]*font-weight:\s*700;[^}]*text-transform:\s*uppercase;/s',
        ] as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $this->customStyles);
        }

        $this->assertSame(
            2,
            substr_count($this->appointmentsPage, 'class="admin-queued-pets-title"'),
        );
        $this->assertSame(
            1,
            substr_count($this->appointmentsPage, 'class="admin-queued-pet-timing"'),
        );
    }

    public function test_create_form_mirrors_validation_and_records_only_advisory_actions(): void
    {
        foreach ([
            'Concern category is required.',
            'Concern category must not exceed 50 characters.',
            'Select Low, Moderate, or Urgent severity.',
            'Internal staff observation is required.',
            'A separate customer-visible message is required.',
            'Select a recommended grooming action.',
            'Acknowledgment requirement must be Yes or No.',
            'Consent requirement must be Yes or No.',
        ] as $message) {
            $this->assertStringContainsString($message, $this->concernComponent);
        }

        foreach ([
            'continue_with_observation',
            'pause_grooming',
            'stop_grooming',
        ] as $action) {
            $this->assertStringContainsString($action, $this->concernComponent);
        }

        $this->assertStringNotContainsString('refer_to_clinic', $this->concernComponent);
        $this->assertStringContainsString(
            'This records the recommended action. It does not yet pause or stop grooming automatically.',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'this.createMedicalConcernReportToken()',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'payload.report_token = this.medicalConcernForm.report_token;',
            $this->concernComponent,
        );

        $payloadBuilder = $this->sourceBetween(
            $this->concernComponent,
            'buildMedicalConcernPayload() {',
            'async saveMedicalConcern() {',
        );
        foreach ([
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'reported_by_user_id',
            'reported_by_name',
            'reported_at',
            'public_id',
            'status:',
            'customer_response_status',
            'customer_notified_at',
            'clinic_appointment_id',
            'applied_grooming_action',
            'resolved_by_user_id',
            'resolved_at',
        ] as $managedField) {
            $this->assertStringNotContainsString($managedField, $payloadBuilder);
        }
    }

    public function test_report_form_uses_sentence_case_labels_and_custom_accessible_dropdowns(): void
    {
        $formMarkup = $this->sourceBetween(
            $this->appointmentsPage,
            '<!-- Create and edit concern form -->',
            '<!-- Send medical concern to customer confirmation -->',
        );

        foreach ([
            'id="medical-concern-severity"',
            'id="medical-concern-recommended-action"',
            'aria-haspopup="listbox"',
            'role="listbox"',
            'role="option"',
            'admin-medical-concern-select-trigger',
            'admin-medical-concern-select-options',
            '@keydown.arrow-down.prevent',
            '@keydown.escape.stop.prevent',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $formMarkup);
        }

        foreach ([
            'Concern category',
            'Internal staff observation',
            'Customer-visible message',
            'Recommended grooming action',
            'Future customer response requirements',
            'Internal resolution notes',
        ] as $label) {
            $this->assertStringContainsString($label, $formMarkup);
        }

        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]+id="medical-concern-(?:severity|recommended-action)"/s',
            $formMarkup,
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-medical-concern-kicker\s*\{[^}]*letter-spacing:\s*0;[^}]*text-transform:\s*none;/s',
            $this->customStyles,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.admin-medical-concern-form-grid > div > label[^}]*text-transform:\s*uppercase/s',
            $this->customStyles,
        );

        foreach ([
            'toggleMedicalConcernSelect(field)',
            'selectMedicalConcernOption(field, value)',
            'focusMedicalConcernSelectOption(listbox, last = false)',
            'moveMedicalConcernSelectFocus(currentOption, offset)',
        ] as $method) {
            $this->assertStringContainsString($method, $this->concernComponent);
        }
    }

    public function test_idempotent_duplicate_locking_and_terminal_controls_follow_api_state(): void
    {
        $this->assertStringContainsString(
            'response.message || (editing',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            '/active medical concern.*category|active.*category/i',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'Open existing concern history',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'customerFieldsEditable: Boolean(concern.customer_visible_fields_editable)',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            ':disabled="!medicalConcernFormModal.customerFieldsEditable"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'medicalConcernActionAvailable(concern, "update")',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            "medicalConcernActionAvailable(concern, 'resolve')",
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            "medicalConcernActionAvailable(concern, 'cancel')",
            $this->appointmentsPage,
        );
        $this->assertStringNotContainsString(
            'deleteAdminBookingPetMedicalConcern',
            $this->apiLayer.$this->concernComponent,
        );
    }

    public function test_cancel_and_resolve_preserve_history_and_require_the_expected_summaries(): void
    {
        foreach ([
            'Cancellation preserves the concern history',
            'It does not delete the record.',
            'Resolution closes the concern while preserving its complete history.',
            'An internal cancellation reason is required.',
            'Internal resolution notes are required.',
            'A customer-safe resolution summary is required.',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            'internal_cancellation_reason: internalReason',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'customer_cancellation_summary: customerSummary || null',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'internal_resolution_notes: internalReason',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'customer_resolution_summary: customerSummary',
            $this->concernComponent,
        );
    }

    public function test_send_to_customer_requires_confirmation_and_refreshes_locked_history(): void
    {
        foreach ([
            'Send to Customer',
            'After sending, customer-visible details can no longer be edited normally.',
            'Customer-visible message',
            'Acknowledgment required',
            'Consent required',
            'No linked customer account',
            'Customer notified',
            'Locked after notification or response',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'openMedicalConcernNotifyDialog(concern)',
            'submitMedicalConcernNotification()',
            'API.notifyCustomerAboutAdminBookingPetMedicalConcern(',
            'await this.loadMedicalConcerns(booking, pet);',
            'medicalConcernActionAvailable(concern, "notify_customer")',
        ] as $behavior) {
            $this->assertStringContainsString(
                $behavior,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            '.admin-medical-concern-send',
            $this->customStyles,
        );
        $this->assertStringNotContainsString(
            'resend',
            strtolower($this->appointmentsPage.$this->concernComponent),
        );
    }

    public function test_operational_action_interface_uses_server_availability_and_explicit_safety_override(): void
    {
        foreach ([
            "medicalConcernActionAvailable(concern, 'apply_recommended_action')",
            "medicalConcernActionAvailable(concern, 'resume_grooming')",
            "openMedicalConcernActionDialog('apply', concern)",
            "openMedicalConcernActionDialog('resume', concern)",
            'API.applyAdminBookingPetMedicalConcernAction(',
            'API.resumeAdminBookingPetGrooming(',
            'safety_override_reason',
            'A safety override reason is required.',
            'This does not create customer consent',
            'Payment review required',
            'Current grooming state',
            'Applied action',
            'recommendedActionEditable: Boolean(concern.recommended_action_editable)',
        ] as $behavior) {
            $this->assertStringContainsString(
                $behavior,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        foreach ([
            'The pet will remain in clinic holding but will no longer count as actively being groomed.',
            'It will not be marked normally finished, and payment still requires staff review.',
            'It does not start or resume grooming automatically.',
            'The pet will return to In progress with its original start time preserved.',
        ] as $wording) {
            $this->assertStringContainsString(
                $wording,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            ':disabled="!medicalConcernFormModal.internalNotesEditable"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            ':disabled="!medicalConcernFormModal.recommendedActionEditable"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'petGroomingState(pet) === "in_progress"',
            $this->dashboardComponent,
        );
    }

    public function test_interface_is_responsive_and_existing_grooming_and_customer_controls_remain(): void
    {
        $this->assertStringContainsString(
            '@media (max-width: 767px)',
            $this->customStyles,
        );
        $this->assertStringContainsString(
            '.admin-medical-concern-dialog',
            $this->customStyles,
        );
        $this->assertStringContainsString(
            'aria-modal="true"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'x-ref="medicalConcernValidationSummary"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'Confirm Start Grooming',
            $this->dashboardComponent,
        );
        $this->assertStringContainsString(
            'confirmMarkPetDone(booking, pet)',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'openPaymentModal(booking',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString('confirmPickedUp(booking)', $this->appointmentsPage);

        $clientPetPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $this->assertStringContainsString('data-pet-panel="grooming"', $clientPetPage);
        $this->assertStringContainsString('data-pet-panel="medical"', $clientPetPage);
        $this->assertStringContainsString('data-pet-panel="vaccinations"', $clientPetPage);
    }

    public function test_concern_component_does_not_call_unrelated_grooming_payment_capacity_or_notification_mutations(): void
    {
        foreach ([
            'adminStartPetGrooming',
            'adminMarkPetDone',
            'processPayment',
            'payNow',
            'releaseBooking',
            'markPickedUp',
            'adminUpdateGroomersOnDuty',
            'getNotifications',
            'markNotificationRead',
            'clinicSaveRecord',
        ] as $unrelatedMutation) {
            $this->assertStringNotContainsString(
                "API.{$unrelatedMutation}",
                $this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            'test_concern_lifecycle_has_no_grooming_payment_notification_clinic_or_inventory_side_effects',
            file_get_contents(base_path('tests/Feature/AdminGroomingMedicalConcernApiTest.php')),
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
