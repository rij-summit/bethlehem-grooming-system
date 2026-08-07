<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminWalkInEntryPointInterfaceTest extends TestCase
{
    public function test_clinic_walk_in_entry_defaults_the_shared_flow_to_clinic(): void
    {
        $clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));

        $this->assertStringContainsString(
            'href="./walk-in-booking.html?flow=clinic&amp;source=clinic"',
            $clinicPage,
        );
        $this->assertStringContainsString(
            'params.get("source") === "clinic" || params.get("flow") === "clinic"',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'if (!isClinicWalkInEntry())',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'document.getElementById("typeClinic")',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'clinicType.checked = true;',
            $ownerStep,
        );
        $this->assertSame(
            2,
            substr_count($walkInPage, 'data-walk-in-back-link'),
        );
        $this->assertStringContainsString(
            'document.querySelectorAll("[data-walk-in-back-link]")',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'link.setAttribute("href", "./clinic.html");',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'link.addEventListener("click", handleWalkInBack);',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'window.location.assign("./clinic.html");',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'walk-in-owner-step.js?v=clinic-origin-navigation-20260807',
            $walkInPage,
        );
        $this->assertStringContainsString(
            'applyRequestedAppointmentType();',
            $ownerStep,
        );
    }

    public function test_other_walk_in_entries_keep_grooming_as_the_default(): void
    {
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $appointmentsPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $dashboardComponent = file_get_contents(base_path('scripts/components/admin-dashboard.js'));

        $this->assertMatchesRegularExpression(
            '/id="typeGrooming"[^>]*value="grooming"[^>]*checked/',
            $walkInPage,
        );
        $this->assertSame(
            2,
            substr_count($walkInPage, 'href="./appointments.html"'),
        );
        $this->assertStringContainsString(
            '@click="handleWalkInBooking()"',
            $appointmentsPage,
        );
        $this->assertStringContainsString(
            'walkInBookingUrl: "./walk-in-booking.html"',
            $dashboardComponent,
        );
    }
}
