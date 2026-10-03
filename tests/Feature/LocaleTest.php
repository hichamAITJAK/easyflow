<?php

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to the configured locale for a guest with no cookie', function () {
    // The suite pins APP_LOCALE=en; the product default in config/app.php is fr.
    config(['app.locale' => 'fr']);

    $this->get(route('login'))->assertOk();

    expect(app()->getLocale())->toBe('fr');
});

it('honours a guest cookie', function () {
    $this->withUnencryptedCookie('locale', 'en')->get(route('login'))->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('prefers the account choice over the cookie', function () {
    $user = makeBusinessUser(['locale' => 'en']);

    $this->actingAs($user)
        ->withUnencryptedCookie('locale', 'fr')
        ->get(route('dashboard'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('falls back to the default for an unknown stored value', function () {
    $this->withUnencryptedCookie('locale', 'de')->get(route('login'))->assertOk();

    expect(app()->getLocale())->toBe(Locale::default()->value);
});

it('lets a user change language and remembers it on the account and in a cookie', function () {
    $user = makeBusinessUser();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('dashboard'))
        ->assertCookie('locale', 'en', encrypted: false);

    expect($user->fresh()->locale)->toBe('en');
});

it('lets a guest change language with a cookie only', function () {
    $this->from(route('login'))
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('login'))
        ->assertCookie('locale', 'en', encrypted: false);

    expect(User::count())->toBe(0);
});

it('rejects a language the app does not offer', function () {
    $this->from(route('login'))
        ->post(route('locale.update'), ['locale' => 'ar'])
        ->assertSessionHasErrors('locale');
});

it('shares the current language and its strings with every page', function () {
    // The suite runs in English (phpunit.xml); ask for French on this one
    // request so the real lang/fr.json is what gets shared. Never writes
    // the file: that is the product's translations, not a fixture.
    $this->withUnencryptedCookie('locale', 'fr')
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->where('locale', 'fr')
            ->where('translations.Log in', 'Se connecter'));
});
