<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\DetermineAccountBlockReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleLoginController extends Controller
{
    private const SESSION_KEY = 'google_registration';

    /**
     * Send the user to Google's consent screen.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the OAuth callback: log in a known account (matching by
     * google_id, then by verified email), or hand a brand-new visitor off
     * to the complete-registration step to name their business.
     */
    public function callback(
        Request $request,
        DetermineAccountBlockReason $blockReason,
    ): RedirectResponse {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'email' => __('Google sign-in was interrupted. Please try again.'),
            ]);
        }

        $user = User::query()
            ->where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user !== null) {
            // Same status gate as the password flow — a disabled account
            // must not slip in through Google.
            if (($reason = $blockReason($user)) !== null) {
                return redirect()->route('account-status', ['reason' => $reason]);
            }

            if ($user->google_id === null) {
                $user->google_id = $googleUser->getId();
                $user->save();
            }

            return $this->logIn($request, $user);
        }

        $request->session()->put(self::SESSION_KEY, [
            'google_id' => $googleUser->getId(),
            'name' => $googleUser->getName() ?? '',
            'email' => $googleUser->getEmail(),
        ]);

        return redirect()->route('auth.google.complete');
    }

    /**
     * Ask the one thing Google can't tell us: the business name.
     */
    public function showComplete(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if ($pending === null) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/google-complete', [
            'name' => $pending['name'],
            'email' => $pending['email'],
        ]);
    }

    /**
     * Create the business and its admin (no local password) — the Google
     * twin of Fortify's CreateNewUser.
     */
    public function complete(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if ($pending === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($pending, $validated) {
            $business = Business::create([
                'name' => $validated['business_name'],
                'slug' => Business::uniqueSlug($validated['business_name']),
            ]);

            $user = User::create([
                'business_id' => $business->id,
                'name' => $pending['name'],
                'email' => $pending['email'],
                'google_id' => $pending['google_id'],
                'role' => UserRole::ADMIN,
                'status' => UserStatus::ACTIVE,
            ]);

            // Google already verified this address.
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });

        $request->session()->forget(self::SESSION_KEY);

        return $this->logIn($request, $user);
    }

    /**
     * Authenticate and land on the dashboard — or the platform panel for a
     * super admin, matching the password and two-factor login responses.
     */
    protected function logIn(Request $request, User $user): RedirectResponse
    {
        Auth::login($user, remember: true);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        // See LoginResponse: a super admin must not follow a stored
        // intended URL into the tenant app, which is closed to them.
        if ($user->role === UserRole::SUPER_ADMIN) {
            $request->session()->forget('url.intended');

            return redirect()->route('super-admin.businesses.index');
        }

        // Likewise a fulfilment agent, whose only web surface is the scan
        // workspace — a stored intended URL would point at a page that
        // logs them straight back out.
        if ($user->role === UserRole::FULFILMENT_AGENT) {
            $request->session()->forget('url.intended');

            return redirect()->route('fulfillment.index');
        }

        if ($user->role === UserRole::CREATIVES_EDITOR) {
            $request->session()->forget('url.intended');

            return redirect()->route('creatives.index');
        }

        return redirect()->intended(route('dashboard'));
    }
}
