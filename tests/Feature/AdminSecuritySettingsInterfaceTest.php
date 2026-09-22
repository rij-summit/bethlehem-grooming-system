<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSecuritySettingsInterfaceTest extends TestCase
{
    public function test_security_tab_exposes_real_admin_and_staff_credential_controls(): void
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
            '>Change credentials</span>',
            "staff.active ? 'Deactivate' : 'Reactivate'",
            "chooseStaffType('clinic')",
            "chooseStaffType('grooming')",
            'x-model="addStaffModal.firstName" required',
            'x-model="addStaffModal.lastName" required',
            'Username <span class="font-normal text-slate-400">(optional)</span>',
            'x-model="addStaffModal.email" required',
            'x-model="addStaffModal.password" required',
            'x-model="addStaffModal.confirmation" required',
            '>6-digit email verification code</legend>',
            '@submit.prevent="confirmNewStaffAccount()"',
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
        $this->assertSame(7, substr_count($security, 'data-lucide="eye-closed"'));
        $this->assertSame(7, substr_count($security, 'data-lucide="eye"'));
        $this->assertStringContainsString(
            'admin-settings.js?v=staff-account-names-20260922',
            $page,
        );
    }

    public function test_security_component_calls_the_protected_credential_change_apis(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $component = file_get_contents(base_path('scripts/components/admin-settings.js'));

        foreach ([
            'x-model="adminPassword.username"',
            '@click="submitAdminPassword()"',
            '@click="requestNewStaffAccount()"',
            '@click="submitResetStaffPassword()"',
            'x-model="resetStaffModal.username"',
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
            'submitResetStaffPassword()',
            'API.getAdminSecurityAccounts()',
            'API.requestAdminCredentialChange({',
            'API.requestStaffCredentialChange(staff.id, {',
            'API.requestStaffAccount({',
            'API.confirmStaffAccountEmail(',
            'API.resendStaffAccountEmailCode(',
            'API.updateStaffAccountStatus(staff.id, targetActive)',
            'API.confirmSecurityCredentialChange(',
            'API.resendSecurityCredentialChangeCode(',
            'openSecurityVerification(response)',
            'password.length < 12',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $component);
        }

        $this->assertStringNotContainsString('staffSearch', $component);
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
        $this->assertSame(13, substr_count($security, 'data-lpignore="true"'));
        $this->assertSame(13, substr_count($security, 'data-1p-ignore'));
        $this->assertSame(13, substr_count($security, 'data-bwignore'));

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
