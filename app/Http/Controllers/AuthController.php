<?php

namespace App\Http\Controllers;

use App\Http\Controllers\EmailVerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;

class AuthController extends Controller
{
    // ── REGISTER ──────────────────────────────────────────
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z\s\'\-]+$/'],
            'last_name'  => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z\s\'\-]+$/'],
            'username'   => 'nullable|string|max:50|unique:users,username',
            'phone'      => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,phone'],
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8|max:100|confirmed',
        ]);

        $user = User::create([
            'first_name'    => $request->first_name,
            'last_name'     => $request->last_name,
            'username'      => $request->username ?: null,
            'email'         => $request->email,
            'phone'         => $request->phone ?: null,
            'password_hash' => Hash::make($request->password),
            'role'          => 'customer',
            'customer_tier' => 'new',
            'is_active'     => 1,
        ]);

        EmailVerificationController::sendVerificationEmail($user);

        return response()->json([
            'success'               => true,
            'message'               => 'Account created. Please check your email to verify your account.',
            'requires_verification' => true,
            'email'                 => $user->email,
        ], 201);
    }

    // ── UNIFIED SIGN-IN ───────────────────────────────────
    public function signIn(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier  = $request->identifier;
        $throttleKey = 'sign-in:' . strtolower($identifier);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success'     => false,
                'message'     => "Too many sign-in attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
        } elseif (preg_match('/^(09|\+639)\d{9}$/', $identifier)) {
            $field = 'phone';
        } else {
            $field = 'username';
        }

        $user = User::where($field, $identifier)->first();

        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been disabled. Please contact the clinic.',
            ], 403);
        }

        // Block unverified customers (admins/staff skip this check)
        if (! in_array($user->role, ['admin', 'staff']) && ! $user->email_verified_at) {
            return response()->json([
                'success'            => false,
                'message'            => 'Please verify your email address before signing in. Check your inbox for the verification link.',
                'email_not_verified' => true,
                'email'              => $user->email,
            ], 403);
        }

        RateLimiter::clear($throttleKey);
        $user->tokens()->delete();
        $tokenName = in_array($user->role, ['admin', 'staff']) ? 'admin_token' : 'auth_token';
        $token     = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role,
            ]
        ], 200);
    }

    // ── LOGOUT ────────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    // ── GET CURRENT USER ──────────────────────────────────
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role,
            ],
        ], 200);
    }
}
