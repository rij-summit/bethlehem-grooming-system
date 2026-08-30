<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class PasswordResetController extends Controller
{
    public function requestLink(Request $request, PasswordResetService $passwordResets)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
        ]);

        try {
            $passwordResets->sendLink($data['email']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The password reset email could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset link has been sent.',
        ], 202);
    }

    public function verifyLink(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);
        $result = $passwordResets->inspect($data['token']);

        if ($result['status'] !== 'valid') {
            return response()->json([
                'success' => false,
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'email' => $result['email'],
        ]);
    }

    public function reset(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);
        $result = $passwordResets->reset($data['token'], $data['password']);

        if ($result['status'] === 'same_password') {
            return response()->json([
                'success' => false,
                'message' => 'Choose a password that is different from the current password.',
            ], 422);
        }

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This password reset link has expired. Request a new one.',
            ], 422);
        }

        if ($result['status'] !== 'reset') {
            return response()->json([
                'success' => false,
                'message' => 'This password reset link is invalid or has already been used.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully.',
        ]);
    }
}
