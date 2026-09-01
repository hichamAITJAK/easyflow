<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Where am I signed in": the user's active browser sessions and mobile
 * devices, with the ability to revoke them.
 *
 * Browser sessions are read from the `sessions` table, which only holds
 * rows while SESSION_DRIVER=database — the file driver writes per-session
 * files that can't be listed per user. Mobile devices are Sanctum personal
 * access tokens, which are queryable regardless.
 */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/sessions', [
            'sessions' => $this->browserSessions($request),
            'devices' => $this->mobileDevices($request->user()),
            'usesDatabaseSessions' => config('session.driver') === 'database',
        ]);
    }

    /**
     * Sign out every other browser session, keeping the one making this
     * request.
     *
     * Password-confirmed: an unattended logged-in browser must not be able
     * to lock the real owner out of their other devices. Laravel's
     * logoutOtherDevices also rotates the password hash in the session, so
     * the other sessions fail their next request.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            return back()->withErrors(['password' => __('This password does not match our records.')]);
        }

        auth()->logoutOtherDevices($request->string('password')->toString());

        $this->deleteOtherSessionRows($request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of every other browser.')]);

        return back();
    }

    /**
     * Revoke one browser session. The current session is not revocable from
     * here — signing yourself out is what the logout button is for, and
     * offering it here makes the list feel like it can break itself.
     */
    public function destroySession(Request $request, string $id): RedirectResponse
    {
        abort_if($id === $request->session()->getId(), HttpResponse::HTTP_FORBIDDEN);

        $deleted = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        abort_if($deleted === 0, HttpResponse::HTTP_NOT_FOUND);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Browser signed out.')]);

        return back();
    }

    /**
     * Revoke one mobile device's token. The phone is signed out the moment
     * its next request is rejected.
     */
    public function destroyDevice(Request $request, int $tokenId): RedirectResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        abort_if($deleted === 0, HttpResponse::HTTP_NOT_FOUND);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device signed out.')]);

        return back();
    }

    /**
     * Revoke every mobile device.
     */
    public function destroyDevices(Request $request): RedirectResponse
    {
        $count = $request->user()->tokens()->count();

        $request->user()->tokens()->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('1 device signed out.|:count devices signed out.', $count),
        ]);

        return back();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function browserSessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'device' => UserAgent::describe($session->user_agent),
                'isMobile' => UserAgent::isMobile($session->user_agent),
                'ipAddress' => $session->ip_address,
                'lastActiveDiff' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'isCurrent' => $session->id === $request->session()->getId(),
            ])
            ->all();

        // array_values(): map() preserves the source keys, and this is
        // serialized to JSON — a non-sequential array would encode as an
        // object rather than the array the client expects.
        return array_values($sessions);
    }

    /**
     * Mobile app sign-ins. The token's `name` is the device name the app
     * sent at login, which is the only label the phone gives us.
     *
     * @return list<array<string, mixed>>
     */
    private function mobileDevices(User $user): array
    {
        $devices = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'lastUsedDiff' => $token->last_used_at?->diffForHumans(),
                'createdDiff' => $token->created_at?->diffForHumans(),
            ])
            ->all();

        return array_values($devices);
    }

    /**
     * logoutOtherDevices invalidates the other sessions but leaves their
     * rows behind, so the list would still show them. This clears them.
     */
    private function deleteOtherSessionRows(Request $request): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }
}
