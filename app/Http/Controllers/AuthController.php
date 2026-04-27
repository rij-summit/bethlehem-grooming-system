<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    // ── REGISTER ──────────────────────────────────────────
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'username'   => 'nullable|string|max:50|unique:users,username',
            'phone'      => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,phone'],
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'first_name'    => $request->first_name,
            'last_name'     => $request->last_name,
            'username'      => $request->username ?: null,
            'email'         => $request->email ?: null,
            'phone'         => $request->phone ?: null,
            'password_hash' => Hash::make($request->password),
            'role'          => 'customer',
            'customer_tier' => 'new',
            'is_active'     => 1,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role,
            ]
        ], 201);
    }

    // ── UNIFIED SIGN-IN ───────────────────────────────────
    public function signIn(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier = $request->identifier;

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
        } elseif (preg_match('/^(09|\+639)\d{9}$/', $identifier)) {
            $field = 'phone';
        } else {
            $field = 'username';
        }

        $user = User::where($field, $identifier)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($field === 'phone' && in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'success' => false,
                'message' => 'Admin accounts must sign in with a username or email.',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been disabled. Please contact the clinic.',
            ], 403);
        }

        $user->tokens()->delete();
        $tokenName = in_array($user->role, ['admin', 'staff']) ? 'admin_token' : 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'role'       => $user->role,
            ]
        ], 200);
    }

    // ── LOGIN ─────────────────────────────────────────────
    public function login(Request $request)
    {
        $request->validate([
            'phone'    => ['required', 'string', 'regex:/^(\+63|0)[0-9]{9,10}$/'],
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid phone number or password',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been disabled. Please contact the clinic.',
            ], 403);
        }

        // Delete old tokens and create new one
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'role'       => $user->role,
            ]
        ], 200);
    }

    // ── ADMIN LOGIN ───────────────────────────────────────
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)
                    ->where('role', 'admin')
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials or unauthorized access.',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been disabled.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Admin login successful',
            'token'   => $token,
            'user'    => [
                'user_id'    => $user->user_id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
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
        return response()->json([
            'success' => true,
            'user'    => $request->user(),
        ], 200);
    }
}