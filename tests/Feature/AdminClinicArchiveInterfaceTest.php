<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminClinicArchiveInterfaceTest extends TestCase
{
    public function test_archive_page_contains_clinic_toggle_empty_state_list_and_full_record_sections(): void
    {
        $page = file_get_contents(base_path('pages/admin/archive.html'));
        $script = file_get_contents(base_path('scripts/components/admin-archive.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        $this->assertStringContainsString("selectArchiveType('grooming')", $page);
        $this->assertStringContainsString("selectArchiveType('clinic')", $page);
        $this->assertStringContainsString('View Full Record', $page);
        $this->assertStringContainsString('Full Archived Clinic Record', $page);
        $this->assertStringContainsString('Visit date and time', $page);
        $this->assertStringContainsString('Patient', $page);
        $this->assertStringContainsString('Owner and Pet Information', $page);
        $this->assertStringContainsString('Appointment Details', $page);
        $this->assertStringContainsString('Reason for Visit', $page);
        $this->assertStringContainsString('Vitals', $page);
        $this->assertStringContainsString('Medical Assessment', $page);
        $this->assertStringContainsString('Treatment and Medication', $page);
        $this->assertStringContainsString('Grooming Referral Information', $page);
        $this->assertStringContainsString('Follow-up and Discharge', $page);
        $this->assertStringContainsString('Payment Information', $page);
        $this->assertStringContainsString('Record Activity', $page);
        $this->assertStringContainsString('Signed corrections or additions', $page);
        $this->assertStringNotContainsString('1. Owner and Pet Basic Information', $page);
        $this->assertStringNotContainsString('2. Basic Appointment Information', $page);
        $this->assertStringNotContainsString('9. Payment Information', $page);
        $this->assertStringContainsString('text-3xl font-extrabold', $page);
        $this->assertStringContainsString('text-lg font-semibold', $page);
        $this->assertStringContainsString('text-base font-semibold', $page);
        $this->assertStringContainsString('text-sm font-medium', $page);
        $this->assertStringContainsString('text-xs font-bold uppercase', $page);

        $this->assertStringContainsString('No clinic archive data is currently available.', $script);
        $this->assertStringContainsString('Data is currently unavailable.', $script);
        $this->assertStringContainsString('API.getArchivedClinicAppointments', $script);
        $this->assertStringContainsString('/admin/clinic-appointments/archived', $api);
    }

    public function test_grooming_referral_section_is_conditionally_shown(): void
    {
        $page = file_get_contents(base_path('pages/admin/archive.html'));

        $this->assertStringContainsString(
            'x-show="detailsClinicRecord?.grooming_referral"',
            $page,
        );
    }

    public function test_clinic_summary_cards_use_sentence_case_before_opening_the_full_record(): void
    {
        $page = file_get_contents(base_path('pages/admin/archive.html'));
        $start = strpos($page, '<template x-if="archiveType === \'clinic\'">');
        $end = strpos($page, '</template>', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $clinicCard = substr($page, $start, $end - $start);

        $this->assertStringContainsString('View Full Record', $clinicCard);
        foreach ([
            'Visit date and time',
            'Appointment type',
            'Reason for visit',
            'Assigned veterinarian',
            'Payment status',
        ] as $label) {
            $this->assertStringContainsString($label, $clinicCard);
        }
        $this->assertStringNotContainsString('uppercase', $clinicCard);
        $this->assertStringNotContainsString('tracking-', $clinicCard);
    }
}
