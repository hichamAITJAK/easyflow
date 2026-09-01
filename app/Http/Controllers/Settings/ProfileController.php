<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'avatarOptions' => collect(glob(public_path('assets/images/avatars/*.png')) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort(SORT_NATURAL)
                ->values(),
        ]);
    }

    /**
     * Update the user's profile information. Role and account status are
     * never accepted here — ProfileUpdateRequest doesn't validate them, so
     * they can't reach this method regardless of what a request carries.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => PhoneNumber::format($data['phone'] ?? null),
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($request->hasFile('avatar')) {
            $this->deleteUploadedAvatar($user);
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        } elseif (! empty($data['avatar_preset'])) {
            $this->deleteUploadedAvatar($user);
            $user->avatar = 'assets/images/avatars/'.$data['avatar_preset'];
        } elseif ($request->boolean('remove_avatar') && $user->avatar) {
            $this->deleteUploadedAvatar($user);
            $user->avatar = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's uploaded avatar file, if any. Preset avatars live in
     * public/assets and are shared between users, so they are never deleted.
     * Uses the raw column value — the accessor prepends the public URL prefix.
     */
    protected function deleteUploadedAvatar(User $user): void
    {
        $path = $user->getRawOriginal('avatar');

        if ($path && str_starts_with($path, 'avatars/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
