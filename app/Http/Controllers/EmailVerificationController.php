<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    // POST /api/email/verify  { token: "..." }
    public function verify(Request $request)
    {
        $request->validate(['token' => 'required|string|size:64']);

        $user = User::where('email_verification_token', $request->token)
            ->whereNull('email_verified_at')
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or already-used verification link.',
            ], 422);
        }

        if ($user->email_verification_expires_at && now()->isAfter($user->email_verification_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'This verification link has expired. Please request a new one.',
                'expired' => true,
                'email'   => $user->email,
            ], 422);
        }

        $user->update([
            'email_verified_at'              => now(),
            'email_verification_token'       => null,
            'email_verification_expires_at'  => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Email verified. Welcome to Bethlehem Animal Clinic!',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role,
            ],
        ]);
    }

    // POST /api/email/resend  { email: "..." }
    public function resend(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)
            ->whereNull('email_verified_at')
            ->first();

        if ($user) {
            self::sendVerificationEmail($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'If that email is registered and unverified, a new verification link has been sent.',
        ]);
    }

    // Shared helper used by register() and resend()
    public static function sendVerificationEmail(User $user): void
    {
        $token = Str::random(64);

        $user->update([
            'email_verification_token'      => $token,
            'email_verification_expires_at' => now()->addHours(24),
        ]);

        $frontendUrl     = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $verificationUrl = "{$frontendUrl}/pages/client/verify-email.html?token={$token}";

        $user->notify(new VerifyEmailNotification($verificationUrl));
    }
}
