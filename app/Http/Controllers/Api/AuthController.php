<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\DetermineAccountBlockReason;
use App\Enums\LoginContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile app authentication (PRD section 9): issues a Sanctum personal
 * access token in exchange for credentials, instead of the web's
 * session-cookie login. Reuses DetermineAccountBlockReason so a
 * disabled/invited user or a suspended/cancelled business is rejected the
 * same way here as on web, rather than re-deriving that logic.
 */
class AuthController extends Controller
{
    public function login(Request $request, DetermineAccountBlockReason $blockReason): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if ($reason = $blockReason($user, LoginContext::MOBILE)) {
            throw ValidationException::withMessages([
                'email' => [$reason],
            ]);
        }

        $token = $user->createToken($credentials['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'business_id' => $user->business_id,
            ],
        ]);
    }

    /**
     * Revoke the token used to authenticate the current request, logging
     * this device out without affecting the user's other sessions/devices.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
