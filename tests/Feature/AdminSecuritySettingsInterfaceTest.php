<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSecuritySettingsInterfaceTest extends TestCase
{
    public function test_security_tab_exposes_admin_controls_and_staff_account_details(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $security = $this->sourceBetween(
            $page,
            'x-show="activeSettingsTab === \'security\'"',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        foreach ([
            '>Admin account</h3>',
            '>Staff accounts</h3>',
            '>Add staff account</span>',
            '>Username</span>',
            '@click="openStaffDetails(staff)"',
            '>Staff account details</h3>',
            '>Staff name</dt>',
            '>Username</dt>',
            '>Email</dt>',
            '>Role</dt>',
            '>Status</dt>',
            'x-text="staff.statusLabel"',
            "staff.active ? 'Deactivate' : 'Reactivate'",
            "chooseStaffType('clinic')",
            "chooseStaffType('grooming')",
            '@click="handleAddStaffClose()"',
            "? 'Back to account roles' : 'Close add staff account form'",
            "chooseClinicSubrole('veterinarian')",
            "chooseClinicSubrole('clinic_receptionist')",
            "addStaffModal.staffSubrole === 'veterinarian' ? 'Veterinarian' : 'Clinic Receptionist'",
            'x-model="addStaffModal.firstName" required',
            'x-model="addStaffModal.lastName" required',
            '>Veterinarian</span>',
            '>Clinic Receptionist</span>',
            '>Grooming Receptionist</span>',
            'Username <span class="font-normal text-slate-400">(optional)</span>',
            'x-model="addStaffModal.email" required',
            '>Staff account created</h3>',
            'Username: <span class="text-[#1f3850]" x-text="addStaffModal.createdUsername"></span>',
            "A Set Up Your Password link has been sent to the staff member's email address.",
            'Update Changes',
            'x-for="staff in filteredStaffAccounts"',
            "staffAccounts.length === 0 ? 'No staff accounts yet' : 'No staff accounts in this category'",
            '>Security verification</p>',
            'x-text="securityVerification.purpose"',
            '>6-digit security code</legend>',
            'x-for="(digit, index) in securityVerification.digits"',
            '@submit.prevent="confirmSecurityCredentialChange()"',
            'resendSecurityCredentialChangeCode()',
        ] as $control) {
            $this->assertStringContainsString($control, $security);
        }

        $this->assertStringNotContainsString('Admin Account Change Password', $security);
        $this->assertStringNotContainsString('Staff Account Change Password', $security);
        $this->assertStringNotContainsString('Use at least 12 characters', $security);
        $this->assertStringNotContainsString('type="search"', $security);
        $this->assertStringNotContainsString('staffSearch', $security);
        $this->assertStringNotContainsString('id="newStaffSubrole"', $security);
        $this->assertStringNotContainsString('>Sub-role</span>', $security);
        $this->assertStringNotContainsString('x-model="addStaffModal.password"', $security);
        $this->assertStringNotContainsString('x-model="addStaffModal.confirmation"', $security);
        $this->assertStringNotContainsString('Change credentials', $security);
        $this->assertStringNotContainsString('resetStaffModal', $security);
        $this->assertStringNotContainsString('6-digit email verification code', $security);
        $this->assertSame(3, substr_count($security, 'data-lucide="eye-closed"'));
        $this->assertSame(3, substr_count($security, 'data-lucide="eye"'));
        $this->assertStringContainsString(
            'admin-settings.js?v=staff-account-details-20260923',
            $page,
        );
    }

    public function test_security_component_calls_admin_credential_and_staff_account_apis(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $component = file_get_contents(base_path('scripts/components/admin-settings.js'));

        foreach ([
            'x-model="adminPassword.username"',
            '@click="submitAdminPassword()"',
            '@click="requestNewStaffAccount()"',
            '@click="openStaffDetails(staff)"',
            'x-show="staffDetailsModal.open"',
            'x-teleport="body"',
            'z-index: 10000',
            'role="dialog"',
            'aria-modal="true"',
        ] as $modalControl) {
            $this->assertStringContainsString($modalControl, $page);
        }

        foreach ([
            'staffAccounts: []',
            'get filteredStaffAccounts()',
            'loadSecurityAccounts()',
            'submitAdminPassword()',
            'openStaffDetails(staff)',
            'closeStaffDetails()',
            'handleAddStaffClose()',
            'API.getAdminSecurityAccounts()',
            'API.requestAdminCredentialChange({',
            'API.requestStaffAccount({',
            'staff_subrole: this.addStaffModal.staffType === "clinic" ? staffSubrole : null',
            'this.addStaffModal.createdUsername = response.username',
            'this.addStaffModal.step = "success"',
            'API.updateStaffAccountStatus(staff.id, targetActive)',
            'API.confirmSecurityCredentialChange(',
            'API.resendSecurityCredentialChangeCode(',
            'openSecurityVerification(response)',
            'password_setup_required',
            'statusLabel:',
            'password.length < 12',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $component);
        }

        $this->assertStringNotContainsString('staffSearch', $component);
        $this->assertStringNotContainsString('resetStaffModal', $component);
        $this->assertStringNotContainsString('requestStaffCredentialChange', $component);
    }

    public function test_security_password_controls_are_not_presented_as_login_forms(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $signIn = file_get_contents(base_path('pages/client/sign-in.html'));
        $security = $this->sourceBetween(
            $page,
            'x-show="activeSettingsTab === \'security\'"',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        $this->assertStringNotContainsString('autocomplete="current-password"', $security);
        $this->assertStringNotContainsString('autocomplete="new-password"', $security);
        $this->assertSame(8, substr_count($security, 'data-lpignore="true"'));
        $this->assertSame(8, substr_count($security, 'data-1p-ignore'));
        $this->assertSame(8, substr_count($security, 'data-bwignore'));

        $this->assertStringContainsString('autocomplete="username"', $signIn);
        $this->assertStringContainsString('autocomplete="current-password"', $signIn);
    }

    public function test_old_email_link_confirmation_interface_has_been_removed(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertFileDoesNotExist(base_path('pages/security/confirm-credential-change.html'));
        $this->assertFileDoesNotExist(base_path('scripts/auth/confirm-credential-change.js'));
        $this->assertStringNotContainsString('previewCredentialChange', $api);
        $this->assertStringNotContainsString("'token' =>", $routes);
        $this->assertStringNotContainsString('credential-changes/preview', $routes);
        $this->assertStringNotContainsString('/admin/security/staff/{staff}/credential-change', $routes);
        $this->assertStringNotContainsString('requestStaffCredentialChange', $api);
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
