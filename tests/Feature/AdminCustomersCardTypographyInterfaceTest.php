<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminCustomersCardTypographyInterfaceTest extends TestCase
{
    public function test_customer_cards_follow_the_clinic_archive_typography_hierarchy(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        $this->assertStringContainsString(
            '<h4 class="text-xl font-bold text-[#1f3850]" x-text="customer.fullName"></h4>',
            $page,
        );
        $this->assertStringContainsString(
            '<h3 class="text-xl font-bold text-[#1f3850]" x-text="detailModal.customer?.fullName || \'Customer Details\'"></h3>',
            $page,
        );
        $this->assertStringContainsString(
            '<h4 class="text-lg font-semibold text-[#1f3850]">Pets</h4>',
            $page,
        );
        $this->assertStringContainsString(
            '<h5 class="text-base font-medium text-[#1f3850]" x-text="formatTextValue(pet.petName)"></h5>',
            $page,
        );
        $this->assertStringContainsString(
            '<span x-show="pet.breed"> · </span>',
            $page,
        );
    }

    public function test_customer_detail_card_labels_use_normal_case_and_medium_weight(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        foreach (['First name', 'Last name', 'Phone number', 'Email address', 'Weight', 'Color'] as $label) {
            $this->assertStringContainsString(
                sprintf('<p class="text-xs font-medium text-slate-500">%s</p>', $label),
                $page,
            );
        }

        $this->assertStringNotContainsString(
            '<p class="text-xs font-medium text-slate-500">Account status</p>',
            $page,
        );
        $this->assertStringContainsString(
            'x-text="customerStatusLabel(detailModal.customer)"',
            $page,
        );

        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">First Name</p>',
            $page,
        );
        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">Weight</p>',
            $page,
        );
    }

    public function test_account_actions_are_below_pets_in_customer_details_modal(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $ownerCard = $this->sourceBetween(
            $page,
            '<!-- Customer Cards -->',
            'x-show="confirmModal.open"',
        );
        $detailsModal = $this->sourceBetween(
            $page,
            '<!-- Customer Details Modal -->',
            'x-show="isAdmin && resetModal.open"',
        );

        $this->assertStringNotContainsString('<!-- Owner actions footer -->', $ownerCard);
        $this->assertStringNotContainsString('openResetPassword(customer)', $ownerCard);
        $this->assertStringNotContainsString("confirmAction('deactivate', customer)", $ownerCard);

        $this->assertStringContainsString('<!-- Account actions below the registered pets -->', $detailsModal);
        $this->assertStringContainsString('<footer x-show="isAdmin && detailModal.customer?.recordType !== \'unregistered\'" aria-label="Account actions"', $detailsModal);
        $this->assertStringContainsString(
            '<h4 class="mb-4 text-lg font-semibold text-[#1f3850]">Account actions</h4>',
            $detailsModal,
        );
        $this->assertStringNotContainsString('divide-y divide-slate-200', $detailsModal);

        foreach ([
            'Help this customer regain access to their account.',
            "Temporarily disable this customer's account.",
            'Move this customer to archived records.',
        ] as $guide) {
            $this->assertStringContainsString($guide, $detailsModal);
        }

        foreach ([
            'openResetPassword(detailModal.customer)',
            "confirmAction('deactivate', detailModal.customer)",
            "confirmAction('archive', detailModal.customer)",
            "confirmAction('reactivate', detailModal.customer)",
            "confirmAction('unarchive', detailModal.customer)",
        ] as $action) {
            $this->assertStringContainsString($action, $detailsModal);
        }

        $petsPosition = strpos($detailsModal, '>Pets</h4>');
        $actionsPosition = strpos($detailsModal, '<!-- Account actions below the registered pets -->');

        $this->assertNotFalse($petsPosition);
        $this->assertNotFalse($actionsPosition);
        $this->assertGreaterThan($petsPosition, $actionsPosition);

        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));

        $this->assertStringContainsString('if (this.detailModal.open) this.closeCustomerDetails();', $component);
        $this->assertStringContainsString('admin-customers.js?v=customer-pet-management-20260810', $page);
    }

    public function test_customer_search_separates_pet_results_and_opens_the_matching_pet_details(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $petSearchResults = $this->sourceBetween(
            $page,
            '<!-- Pet search results -->',
            'x-show="confirmModal.open"',
        );

        foreach ([
            '>Customers</h3>',
            '>Pets</h3>',
            "x-text=\"'(' + customers.length + ')'\"",
            "x-text=\"'(' + petSearchResults.length + ')'\"",
            '@click="openPetSearchResult(pet)"',
            'Owner: <span class="font-medium text-slate-700" x-text="pet.ownerName"></span>',
            'data-lucide="cat"',
            'data-lucide="dog"',
            'x-text="formatMobileNumber(pet.ownerPhone)"',
            'x-text="formatTextValue(pet.ownerEmail)"',
            'placeholder="Search customer, pet, or phone..."',
        ] as $searchUi) {
            $this->assertStringContainsString($searchUi, $page);
        }

        foreach ([
            'petSearchResults: []',
            'this.petSearchResults = data.pets || [];',
            'async openPetSearchResult(result)',
            'await this.openCustomerDetails({',
            'this.openPetDetail(pet);',
        ] as $searchBehavior) {
            $this->assertStringContainsString($searchBehavior, $component);
        }

        $this->assertStringNotContainsString('>CUSTOMERS</h3>', $page);
        $this->assertStringNotContainsString('>PETS</h3>', $page);
        $this->assertSame(1, substr_count($petSearchResults, '&middot;'));
    }

    public function test_pet_view_details_matches_the_owner_card_typography(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $petModal = $this->sourceBetween(
            $page,
            '<!-- Pet Detail Modal -->',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        $this->assertStringContainsString(
            'class="text-xl font-semibold text-[#1f3850]"',
            $petModal,
        );
        $this->assertStringContainsString(
            'class="rounded-2xl border border-slate-200 bg-[#f8fbfd] p-5 sm:p-6"',
            $petModal,
        );
        $this->assertStringContainsString(
            'class="grid gap-6 sm:grid-cols-2"',
            $petModal,
        );

        foreach ([
            'Gender',
            'Birthdate',
            'Neutered / spayed',
            'Neutered date',
            'Deceased',
            'Deceased date',
            'Size',
            'Fur type',
            'Weight',
            'Color',
            'Medical conditions / special needs',
        ] as $label) {
            $this->assertStringContainsString(
                sprintf('<p class="text-xs font-normal leading-5 text-slate-500">%s</p>', $label),
                $petModal,
            );
        }

        $this->assertGreaterThanOrEqual(
            9,
            substr_count($petModal, 'mt-1.5 text-sm font-semibold leading-6'),
        );

        $this->assertStringNotContainsString('font-bold uppercase', $petModal);
        $this->assertStringNotContainsString('font-extrabold', $petModal);
    }

    public function test_admin_pet_edit_reuses_customer_custom_fields_and_connected_behavior(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $controller = file_get_contents(base_path('app/Http/Controllers/PetController.php'));
        $petForm = $this->sourceBetween(
            $page,
            '<!-- Edit mode -->',
            '<!-- Footer -->',
        );

        foreach ([
            'adminPetSpeciesCombobox',
            'adminPetGenderCombobox',
            'adminPetBreedCombobox',
            'adminPetSizeCombobox',
            'adminPetFurTypeCombobox',
        ] as $combobox) {
            $this->assertStringContainsString("id=\"{$combobox}\"", $petForm);
        }

        $this->assertStringNotContainsString('<select', $petForm);
        $this->assertStringNotContainsString('font-bold uppercase', $petForm);

        foreach ([
            'import("./breed-combobox.js")',
            'import("./breed-coat-combobox.js")',
            'import("./fixed-option-combobox.js")',
            'import("./pet-weight-size.js")',
            'tools.createBreedCombobox({',
            'tools.createBreedCoatCombobox({',
            'tools.createFixedOptionCombobox({',
            'tools.getSizeForWeight(',
            'tools.getWeightValidationMessage(',
            'tools.showWeightRangeInField(',
            'tools.normalizePetSize(',
        ] as $sharedBehavior) {
            $this->assertStringContainsString($sharedBehavior, $component);
        }

        foreach (['new ValidPetSize', 'new ValidBreedCoat', 'new ValidPetWeight', 'PetWeightSize::withComputedSize($data)'] as $rule) {
            $this->assertStringContainsString($rule, $controller);
        }
    }

    public function test_unregistered_tab_and_add_customer_use_a_separate_customers_ui(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $api = file_get_contents(base_path('scripts/api.js'));
        $addCustomerModal = $this->sourceBetween(
            $page,
            '<!-- Add Customer Modal -->',
            '<!-- Customer Details Modal -->',
        );

        foreach ([
            "@click=\"setStatus('unregistered')\"",
            "statusFilter === 'unregistered' ? totalCount : '?'",
            'No unregistered customers yet.',
            'Customers added without an online account will appear here.',
            '@click="openAddCustomerModal()"',
            'customer.recordType === \'unregistered\'',
            'Unregistered (<span',
        ] as $unregisteredUi) {
            $this->assertStringContainsString($unregisteredUi, $page);
        }

        foreach ([
            'id="addCustomerFirstName"',
            'id="addCustomerLastName"',
            'id="addCustomerMiddleName"',
            'id="addCustomerPhone"',
            'id="addCustomerEmail"',
            '@submit.prevent="submitUnregisteredCustomer()"',
            'Create a customer record without an online account.',
        ] as $formUi) {
            $this->assertStringContainsString($formUi, $addCustomerModal);
        }

        $this->assertStringNotContainsString('walkInOwnerForm', $addCustomerModal);
        $this->assertStringNotContainsString('uppercase tracking', $addCustomerModal);
        $this->assertStringContainsString('async submitUnregisteredCustomer(confirmSimilarName = false)', $component);
        $this->assertStringContainsString('API.createUnregisteredCustomer(payload)', $component);
        $this->assertStringContainsString('return "Unregistered";', $component);
        $this->assertStringContainsString('async function createUnregisteredCustomer(payload)', $api);
        $this->assertStringContainsString('/admin/customers/unregistered', $api);

        $activePosition = strpos($page, "setStatus('active')");
        $unregisteredPosition = strpos($page, "setStatus('unregistered')");
        $inactivePosition = strpos($page, "setStatus('inactive')");
        $archivePosition = strpos($page, "setStatus('archived')");
        $this->assertLessThan($unregisteredPosition, $activePosition);
        $this->assertLessThan($inactivePosition, $unregisteredPosition);
        $this->assertLessThan($archivePosition, $inactivePosition);

        foreach ([
            'Similar customer found',
            'submitUnregisteredCustomer(true)',
            "confirmAction('archive_unregistered', detailModal.customer)",
            'openAddPetForCustomer(detailModal.customer)',
            'API.adminAddCustomerPet(',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $page.$component);
        }
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
