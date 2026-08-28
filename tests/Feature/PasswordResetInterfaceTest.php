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
            'data-lucide="eye-closed"',
            'data-lucide="eye"',
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
        $this->assertStringContainsString(
            'window.location.replace("./sign-in.html?password_reset=1")',
            $resetScript,
        );
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
}
