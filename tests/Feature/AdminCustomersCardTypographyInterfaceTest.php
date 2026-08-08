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
            '<h4 class="text-lg font-semibold text-[#1f3850]">Registered Pets</h4>',
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

        foreach (['First name', 'Last name', 'Phone number', 'Email address', 'Account status', 'Weight', 'Color'] as $label) {
            $this->assertStringContainsString(
                sprintf('<p class="text-xs font-medium text-slate-500">%s</p>', $label),
                $page,
            );
        }

        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">First Name</p>',
            $page,
        );
        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">Weight</p>',
            $page,
        );
    }
}
