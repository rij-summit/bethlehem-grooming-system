<?php

namespace Tests\Feature;

use Tests\TestCase;

class PasswordResetInterfaceTest extends TestCase
{
    public function test_sign_in_and_password_reset_pages_expose_the_email_link_flow(): void
    {
        $signIn = file_get_contents(base_path('pages/client/sign-in.html'));
        $forgot = file_get_contents(base_path('pages/client/forgot-password.html'));
        $reset = file_get_contents(base_path('pages/client/reset-password.html'));
        $forgotScript = file_get_contents(base_path('scripts/auth/forgot-password.js'));
        $resetScript = file_get_contents(base_path('scripts/auth/reset-password.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        $this->assertStringContainsString('href="./forgot-password.html"', $signIn);
        $this->assertStringNotContainsString('Forgot password?" has no password reset flow', $signIn);
        $this->assertStringContainsString('id="forgotPasswordForm"', $forgot);
        $this->assertStringContainsString('type="email"', $forgot);
        $this->assertStringContainsString('API.requestPasswordReset(email)', $forgotScript);

        foreach ([
            'id="resetPasswordForm"',
            'id="newPassword"',
            'id="confirmNewPassword"',
            'autocomplete="off"',
            'data-lpignore="true"',
            'data-1p-ignore="true"',
            'phosphor.svg#eye-slash',
            'phosphor.svg#eye',
        ] as $control) {
            $this->assertStringContainsString($control, $reset);
        }

        $this->assertStringContainsString(
            'new URLSearchParams(window.location.hash.slice(1))',
            $resetScript,
        );
        $this->assertStringContainsString('cleanUrl.hash = ""', $resetScript);
        $this->assertLessThan(
            strpos($resetScript, 'await API.verifyPasswordResetToken(resetToken)'),
            strpos($resetScript, 'history.replaceState('),
        );
        $this->assertStringContainsString('await API.resetPassword(', $resetScript);
        $this->assertStringContainsString('response.completed_setup', $resetScript);
        $this->assertStringContainsString('"../admin/dashboard.html"', $resetScript);
        $this->assertStringContainsString('"./sign-in.html?password_reset=1"', $resetScript);
        $this->assertStringNotContainsString('one-time-code', $reset);
        $this->assertStringNotContainsString('6-digit', $reset);
        $this->assertStringNotContainsString('autocomplete="new-password"', $reset);

        foreach ([
            'requestPasswordReset',
            'verifyPasswordResetToken',
            'resetPassword',
            '"/password/forgot"',
            '"/password/reset/verify"',
            '"/password/reset"',
        ] as $apiContract) {
            $this->assertStringContainsString($apiContract, $api);
        }
    }

    public function test_staff_password_setup_page_uses_the_login_design_without_login_extras(): void
    {
        $page = file_get_contents(base_path('pages/client/set-up-password.html'));
        $script = file_get_contents(base_path('scripts/auth/set-up-password.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        foreach ([
            'Bethlehem Animal Clinic Logo',
            '>Set Up Your Password</h1>',
            '>Create a password to secure your account</p>',
            '>New password</label>',
            '>Confirm new password</label>',
            'autocomplete="new-password"',
            'phosphor.svg#eye-slash',
            'phosphor.svg#eye',
            '>Create Account</span>',
            'id="setupExpiredLink"',
            'This setup link has expired. Request a new link to finish setting up your account.',
            '>Request New Setup Link</button>',
        ] as $content) {
            $this->assertStringContainsString($content, $page);
        }

        foreach ([
            'Remember me',
            'Forgot password?',
            "Don't have an account?",
            '>Home</a>',
            'home-ai-chatbot.js',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, $page);
        }

        $this->assertSame(2, substr_count($page, 'autocomplete="new-password"'));
        $this->assertStringContainsString('await API.verifyStaffPasswordSetupToken(setupToken)', $script);
        $this->assertStringContainsString('await API.completeStaffPasswordSetup(', $script);
        $this->assertStringContainsString('API.requestNewStaffPasswordSetupLink(setupToken)', $script);
        $this->assertStringContainsString('password.disabled = true', $script);
        $this->assertStringContainsString('confirmation.disabled = true', $script);
        $this->assertStringContainsString('window.location.replace("../admin/dashboard.html")', $script);
        $this->assertStringContainsString('submit.disabled = busy || validationError() !== ""', $script);
        $this->assertStringContainsString('"set-up-password.html"', $api);
        $this->assertStringContainsString('"/staff/password-setup/verify"', $api);
        $this->assertStringContainsString('"/staff/password-setup/complete"', $api);
        $this->assertStringContainsString('"/staff/password-setup/request-new-link"', $api);
    }
}
