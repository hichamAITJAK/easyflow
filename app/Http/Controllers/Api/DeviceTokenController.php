<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRD section 9: "the app registers a push token on login and sends it to
 * the backend." token is unique at the DB level, keyed there rather than
 * on (user_id, token) — the same physical device's Expo token must belong
 * to exactly one user at a time, so a re-registration (e.g. a different
 * user logging in on a shared/reused device) reassigns it instead of
 * leaving a stale row pointing at the previous account.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:50'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $deviceToken = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'device_type' => $data['device_type'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['id' => $deviceToken->id], 201);
    }

    /**
     * Unregister a device token, e.g. on logout — a signed-out device must
     * stop receiving pushes for the account it's no longer authenticated
     * as, rather than keep notifying whoever's currently on that device.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Device token removed.']);
    }
}
