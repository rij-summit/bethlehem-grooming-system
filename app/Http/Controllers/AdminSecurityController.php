<?php

namespace App\Http\Controllers;

use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Services\PrivilegedCredentialChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'is_active' => (bool) $user->is_active,
            'is_archived' => (bool) $user->is_archived,
        ];
    }
}
