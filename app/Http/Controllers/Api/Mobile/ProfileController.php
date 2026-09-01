<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\ProfileUpdateRequest;
use App\Http\Resources\MobileUserResource;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;

/**
 * Mobile counterpart to Settings\ProfileController::update — name, email,
 * and phone only (see ProfileUpdateRequest docblock for why avatar is
 * excluded here).
 */
class ProfileController extends Controller
{
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => PhoneNumber::format($data['phone'] ?? null),
        ]);

        $user->save();

        return response()->json(['user' => MobileUserResource::make($user)]);
    }
}
