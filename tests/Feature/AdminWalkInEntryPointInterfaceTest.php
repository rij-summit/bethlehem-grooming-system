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
            'walk-in-owner-step.js?v=clinic-existing-customers-20260813',
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

    public function test_grooming_walk_in_can_select_existing_owners_and_their_pets(): void
    {
        $ownerPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $petPage = file_get_contents(base_path('pages/admin/walk-in-pet-details.html'));
        $petStep = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));
        $servicesStep = file_get_contents(base_path('scripts/components/walk-in-services-step.js'));
        $consentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        foreach ([
            'Find an existing customer',
            'Search active and unregistered customers by name, mobile number, or email.',
            'id="existingCustomerSearch"',
            'placeholder="Enter at least 2 characters"',
            'New owner information',
        ] as $content) {
            $this->assertStringContainsString($content, $ownerPage);
        }

        $this->assertStringNotContainsString(
            'Use this form when the customer is not in the search results.',
            $ownerPage,
        );

        foreach ([
            'API.searchWalkInCustomers(query)',
            'ownerRecordType: selectedCustomer?.recordType || "new"',
            'existingPets: Array.isArray(selectedCustomer?.pets)',
            'window.location.href = "./walk-in-pet-details.html";',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $ownerStep);
        }

        $this->assertStringContainsString('id="existingPetsSection"', $petPage);
        $this->assertStringContainsString('data-select-existing-pet', $petStep);
        $this->assertStringContainsString('petId: pet.id', $petStep);
        $this->assertStringContainsString('...pet,', $servicesStep);
        $this->assertStringContainsString('pet_id:              item.pet.petId || null', $consentStep);
        $this->assertStringContainsString('owner_record_type: owner.ownerRecordType || "new"', $consentStep);
    }

    public function test_clinic_walk_in_can_select_existing_owners_and_their_pets(): void
    {
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $petStep = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));
        $clinicConsentStep = file_get_contents(base_path('scripts/components/walk-in-clinic-consent-step.js'));

        $this->assertStringContainsString(
            'elements.existingCustomerSection?.classList.remove("hidden");',
            $ownerStep,
        );
        $this->assertStringNotContainsString(
            'document.getElementById("typeGrooming").checked = true;',
            $ownerStep,
        );
        $this->assertStringContainsString(
            '"./walk-in-booking.html?flow=clinic&source=clinic"',
            $petStep,
        );

        foreach ([
            'owner_record_type: owner.ownerRecordType || "new"',
            'customer_user_id: owner.customerUserId || null',
            'unregistered_customer_id: owner.unregisteredCustomerId || null',
            'pet_id:             state.pet?.petId || null',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $clinicConsentStep);
        }
    }

    public function test_new_owner_validation_uses_a_bottom_left_similar_name_warning(): void
    {
        $ownerPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $consentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        foreach ([
            'id="similarOwnerWarning"',
            'fixed bottom-5 left-5',
            'Similar customer found',
            'id="reviewSimilarOwnerBtn"',
            'id="continueSimilarOwnerBtn"',
            'Continue anyway',
            'api.js?v=auth-session-20260816',
        ] as $content) {
            $this->assertStringContainsString($content, $ownerPage);
        }

        $this->assertStringContainsString('API.validateWalkInNewOwner({', $ownerStep);
        $this->assertStringContainsString('error.code === "similar_customer_name"', $ownerStep);
        $this->assertStringContainsString('showSimilarOwnerWarning(values', $ownerStep);
        $this->assertStringContainsString('confirm_similar_name: Boolean(owner.confirmSimilarName)', $consentStep);
    }
}
