<?php

use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a row shaped the way Laravel's database session driver writes one.
 */
function sessionRow(User $user, string $id, ?string $userAgent = null, ?int $lastActivity = null): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => $userAgent ?? 'Mozilla/5.0 (Macintosh) Chrome/120.0',
        'payload' => base64_encode('test'),
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);
}

it('lists the user\'s browser sessions and mobile devices', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    sessionRow($user, 'session-one');
    $user->createToken('Kareem\'s iPhone');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/sessions')
            ->has('sessions', 1)
            ->where('sessions.0.device', 'Chrome on macOS')
            ->has('devices', 1)
            ->where('devices.0.name', 'Kareem\'s iPhone')
            ->where('usesDatabaseSessions', true)
        );
});

it('never shows another user\'s sessions or devices', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    $other = makeBusinessUser();

    sessionRow($other, 'not-mine');
    $other->createToken('Their phone');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'))
        ->assertInertia(fn ($page) => $page
            ->has('sessions', 0)
            ->has('devices', 0)
        );
});

it('revokes one browser session', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    sessionRow($user, 'doomed');

    $this->actingAs($user)
        ->delete(route('sessions.destroy', 'doomed'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'doomed')->exists())->toBeFalse();
});

it('refuses to revoke a session belonging to someone else', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    $other = makeBusinessUser();
    sessionRow($other, 'theirs');

    $this->actingAs($user)
        ->delete(route('sessions.destroy', 'theirs'))
        ->assertNotFound();

    // A 404 must not be a delete that merely reported failure.
    expect(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();
});

it('revokes a single mobile device', function () {
    $user = makeBusinessUser();
    $keep = $user->createToken('Keep me');
    $drop = $user->createToken('Drop me');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-device', $drop->accessToken->id))
        ->assertRedirect();

    expect($user->tokens()->pluck('id')->all())->toBe([$keep->accessToken->id]);
});

it('refuses to revoke a device belonging to someone else', function () {
    $user = makeBusinessUser();
    $other = makeBusinessUser();
    $theirs = $other->createToken('Their phone');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-device', $theirs->accessToken->id))
        ->assertNotFound();

    expect($other->tokens()->count())->toBe(1);
});

it('revokes every mobile device at once', function () {
    $user = makeBusinessUser();
    $user->createToken('Phone');
    $user->createToken('Tablet');

    $other = makeBusinessUser();
    $other->createToken('Untouched');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-devices'))
        ->assertRedirect();

    expect($user->tokens()->count())->toBe(0)
        ->and($other->tokens()->count())->toBe(1);
});

it('signs out other browsers when the password is correct', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    sessionRow($user, 'other-browser');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other-browser')->exists())->toBeFalse();
});

it('rejects signing out other browsers with a wrong password', function () {
    config(['session.driver' => 'database']);

    $user = makeBusinessUser();
    sessionRow($user, 'other-browser');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-others'), ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    // The whole point of the password gate: nothing is revoked without it.
    expect(DB::table('sessions')->where('id', 'other-browser')->exists())->toBeTrue();
});

it('tells the page when sessions cannot be listed', function () {
    config(['session.driver' => 'file']);

    $this->actingAs(makeBusinessUser())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('usesDatabaseSessions', false)
            ->has('sessions', 0)
        );
});

it('requires authentication', function () {
    $this->get(route('sessions.index'))->assertRedirect(route('login'));
});

it('requires a confirmed password before showing where the account is signed in', function () {
    $this->actingAs(makeBusinessUser())
        ->get(route('sessions.index'))
        ->assertRedirect(route('password.confirm'));
});

it('describes common user agents', function (string $agent, string $expected) {
    expect(UserAgent::describe($agent))->toBe($expected);
})->with([
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Chrome/120.0 Safari/537.36', 'Chrome on macOS'],
    ['Mozilla/5.0 (Windows NT 10.0) Firefox/121.0', 'Firefox on Windows'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', 'Safari on iOS'],
    ['Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile', 'Chrome on Android'],
    // Edge and Opera both claim Chrome, so ordering decides these.
    ['Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36 Edg/120.0', 'Edge on Windows'],
    ['Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36 OPR/106.0', 'Opera on macOS'],
    ['', 'Unknown device'],
    ['some-cli-tool/1.0', 'Unknown device'],
]);

it('flags mobile user agents', function () {
    expect(UserAgent::isMobile('Mozilla/5.0 (iPhone) Safari/604.1'))->toBeTrue()
        ->and(UserAgent::isMobile('Mozilla/5.0 (Linux; Android 14) Mobile'))->toBeTrue()
        ->and(UserAgent::isMobile('Mozilla/5.0 (Macintosh) Chrome/120.0'))->toBeFalse()
        ->and(UserAgent::isMobile(null))->toBeFalse();
});
