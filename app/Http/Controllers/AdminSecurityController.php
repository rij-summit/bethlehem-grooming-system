<?php

namespace App\Http\Controllers;

use App\Models\LoginEmailChallenge;
use App\Models\PendingStaffAccount;
use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Services\PendingStaffAccountService;
use App\Services\PrivilegedCredentialChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSecurityController extends Controller
{
    public function accounts(Request $request)
    {
        $admin = $request->user();
        $staff = User::query()
            ->where('role', 'staff')
            ->where('is_archived', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $user): array => $this->accountPayload($user))
            ->values();

        return response()->json([
            'success' => true,
            'admin' => $this->accountPayload($admin),
            'staff' => $staff,
        ]);
    }

    public function requestOwnChange(
        Request $request,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        $data = $this->validateChange($request, requireCurrentPassword: true);
        $admin = $request->user();

        if (! Hash::check($data['current_password'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        return $this->requestChange(
            $credentialChanges,
            $admin,
            $admin,
            $data,
        );
    }

    public function requestStaffChange(
        Request $request,
        User $staff,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        if ($staff->role !== 'staff') {
            abort(404);
        }

        $data = $this->validateChange($request);

        return $this->requestChange(
            $credentialChanges,
            $request->user(),
            $staff,
            $data,
        );
    }

    public function requestStaffAccount(
        Request $request,
        PendingStaffAccountService $staffAccounts,
    ) {
        $firstName = $request->input('first_name');
        $lastName = $request->input('last_name');
        $username = trim((string) $request->input('username'));
        $request->merge([
            'first_name' => is_string($firstName) ? User::normalizeName($firstName) : $firstName,
            'last_name' => is_string($lastName) ? User::normalizeName($lastName) : $lastName,
            'email' => Str::lower(trim((string) $request->input('email'))),
            'username' => $username === '' ? null : $username,
        ]);
        $data = $request->validate([
            'staff_type' => ['required', Rule::in(['clinic', 'grooming'])],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Za-z][A-Za-z0-9._-]{2,49}$/',
            ],
            'email' => ['required', 'email', 'max:150'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);

        try {
            $result = $staffAccounts->request(
                $request->user(),
                $data['staff_type'],
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['email'],
                $data['password'],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The staff email verification code could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'A six-digit email verification code was sent.',
        ] + $result, 202);
    }

    public function confirmStaffAccount(
        Request $request,
        PendingStaffAccount $pendingStaff,
        PendingStaffAccountService $staffAccounts,
    ) {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $result = $staffAccounts->confirm($request->user(), $pendingStaff, $data['code']);

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This email verification code has expired. Start again.',
            ], 422);
        }
        if ($result['status'] === 'invalid_code') {
            return response()->json([
                'success' => false,
                'message' => 'The email verification code is incorrect.',
                'attempts_remaining' => $result['attempts_remaining'],
            ], 422);
        }
        if ($result['status'] === 'locked') {
            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect attempts. Start again.',
                'attempts_remaining' => 0,
            ], 422);
        }
        if ($result['status'] === 'email_unavailable') {
            return response()->json([
                'success' => false,
                'message' => 'That email address is no longer available.',
            ], 422);
        }
        if ($result['status'] === 'username_unavailable') {
            return response()->json([
                'success' => false,
                'message' => 'That username is no longer available.',
            ], 422);
        }
        if ($result['status'] !== 'created') {
            return response()->json([
                'success' => false,
                'message' => 'This staff email verification is invalid or no longer available.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "{$result['staff_label']} account created.",
            'staff' => $this->accountPayload($result['staff']),
        ], 201);
    }

    public function resendStaffAccountCode(
        Request $request,
        PendingStaffAccount $pendingStaff,
        PendingStaffAccountService $staffAccounts,
    ) {
        try {
            $result = $staffAccounts->resend($request->user(), $pendingStaff);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The staff email verification code could not be queued. Please try again.',
            ], 503);
        }

        if ($result['status'] === 'cooldown') {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another code.',
                'retry_after' => $result['retry_after'],
            ], 429);
        }
        if ($result['status'] !== 'resent') {
            return response()->json([
                'success' => false,
                'message' => 'This staff email verification is no longer available.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'A new email verification code was sent.',
        ] + $result);
    }

    public function updateStaffStatus(Request $request, User $staff)
    {
        if ($staff->role !== 'staff' || $staff->is_archived) {
            abort(404);
        }

        $data = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $data['active'];
        $staff->is_active = $active;
        $staff->save();

        if (! $active) {
            $staff->tokens()->delete();
            LoginEmailChallenge::query()->where('user_id', $staff->user_id)->delete();
            PrivilegedCredentialChange::query()
                ->where('target_user_id', $staff->user_id)
                ->whereNull('confirmed_at')
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $active
                ? 'Staff account reactivated.'
                : 'Staff account deactivated.',
            'staff' => $this->accountPayload($staff->fresh()),
        ]);
    }

    public function confirm(
        Request $request,
        PrivilegedCredentialChange $change,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Enter the six-digit security code.',
        ]);
        $result = $credentialChanges->confirm(
            $request->user(),
            $change,
            $data['code'],
        );

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This security code has expired. Request a new code.',
            ], 422);
        }

        if ($result['status'] === 'invalid_code') {
            return response()->json([
                'success' => false,
                'message' => 'The security code is incorrect.',
                'attempts_remaining' => $result['attempts_remaining'],
            ], 422);
        }

        if ($result['status'] === 'locked') {
            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect attempts. Request a new code.',
                'attempts_remaining' => 0,
            ], 422);
        }

        if ($result['status'] === 'username_unavailable') {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        if ($result['status'] !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'This security verification is invalid or has already been used.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "{$result['purpose']} confirmed.",
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'target_role' => $result['target_role'],
            'changes' => $result['changes'],
            'requires_reauthentication' => $result['requires_reauthentication'],
        ]);
    }

    public function resend(
        Request $request,
        PrivilegedCredentialChange $change,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        try {
            $result = $credentialChanges->resend($request->user(), $change);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The security code email could not be queued. Please try again.',
            ], 503);
        }

        if ($result['status'] === 'cooldown') {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another code.',
                'retry_after' => $result['retry_after'],
            ], 429);
        }

        if ($result['status'] !== 'resent') {
            return response()->json([
                'success' => false,
                'message' => 'This security verification is no longer available.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'A new security code was sent.',
            'change_id' => $result['change_id'],
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'confirmation_email' => $result['confirmation_email'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    private function requestChange(
        PrivilegedCredentialChangeService $credentialChanges,
        User $requester,
        User $target,
        array $data,
    ) {
        try {
            $result = $credentialChanges->request(
                $requester,
                $target,
                $data['username'] ?? null,
                $data['password'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The security code email could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'A six-digit security code was sent.',
            'change_id' => $result['change_id'],
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'confirmation_email' => $result['confirmation_email'],
            'expires_at' => $result['expires_at'],
        ], 202);
    }

    private function validateChange(Request $request, bool $requireCurrentPassword = false): array
    {
        return $request->validate([
            'current_password' => $requireCurrentPassword
                ? ['required', 'string']
                : ['prohibited'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Za-z][A-Za-z0-9._-]{2,49}$/',
            ],
            'password' => [
                'nullable',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['nullable', 'string'],
        ], [
            'username.regex' => 'Use letters, numbers, periods, underscores, or hyphens, beginning with a letter.',
        ]);
    }

    private function accountPayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'staff_type' => $user->staff_type,
            'is_active' => (bool) $user->is_active,
            'is_archived' => (bool) $user->is_archived,
        ];
    }
}
