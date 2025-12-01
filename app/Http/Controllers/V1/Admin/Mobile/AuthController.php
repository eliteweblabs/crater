<?php

namespace Crater\Http\Controllers\V1\Admin\Mobile;

use Crater\Http\Controllers\Controller;
use Crater\Http\Requests\LoginRequest;
use Crater\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->username)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // CRITICAL FIX: Create session for SPA (not just token for mobile)
        Auth::login($user, true);

        return response()->json([
            'type' => 'Bearer',
            'token' => $user->createToken($request->device_name ?? 'web')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    public function check()
    {
        return Auth::check();
    }
}
