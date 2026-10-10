<?php

use App\Enums\Courier;
use App\Enums\UserRole;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryCourrier;
use App\Models\EcommercePlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------- platforms

it('lists platforms with their connected store counts', function () {
    $platform = EcommercePlatform::factory()->create(['name' => 'Shopify']);
    makeStore(makeBusinessUser()->business_id, ['platform_id' => $platform->id]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.platforms.index'))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/platforms/index')
            ->where('platforms.0.name', 'Shopify')
            ->where('platforms.0.stores_count', 1)
        );
});

it('updates a platform name and description', function () {
    $platform = EcommercePlatform::factory()->create(['slug' => 'Shopify']);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), [
            'name' => 'Shopify Plus',
            'description' => 'Updated copy.',
        ])
        ->assertRedirect(route('super-admin.platforms.index'));

    expect($platform->fresh())
        ->name->toBe('Shopify Plus')
        ->description->toBe('Updated copy.');
});

it('uploads a platform logo and stores its path', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create(['logo_url' => null]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), [
            'name' => 'Shopify',
            'logo' => UploadedFile::fake()->image('shopify.png'),
        ])
        ->assertRedirect();

    $stored = $platform->fresh()->getRawOriginal('logo_url');

    expect($stored)->toStartWith('platform-logos/');
    Storage::disk('public')->assertExists($stored);
});

it('replaces an uploaded platform logo and deletes the old file', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create(['logo_url' => null]);

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Shopify',
        'logo' => UploadedFile::fake()->image('first.png'),
    ]);

    $first = $platform->fresh()->getRawOriginal('logo_url');

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Shopify',
        'logo' => UploadedFile::fake()->image('second.png'),
    ]);

    $second = $platform->fresh()->getRawOriginal('logo_url');

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('never deletes a seeded logo that ships with the repo', function () {
    Storage::fake('public');

    // Seeded logos live in public/assets and are shared across environments,
    // so replacing one must upload the new file without unlinking the repo
    // asset the old value pointed at.
    $platform = EcommercePlatform::factory()->create([
        'logo_url' => 'assets/images/shopify_logo.png',
    ]);

    // Put a decoy at that path on the fake disk: if the cleanup mistakenly
    // treated a seeded path as an upload, this is what it would delete.
    Storage::disk('public')->put('assets/images/shopify_logo.png', 'seeded');

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Shopify',
        'logo' => UploadedFile::fake()->image('new.png'),
    ]);

    Storage::disk('public')->assertExists('assets/images/shopify_logo.png');
    expect($platform->fresh()->getRawOriginal('logo_url'))->toStartWith('platform-logos/');
});

it('removes a platform logo on request', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create(['logo_url' => null]);

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Shopify',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $path = $platform->fresh()->getRawOriginal('logo_url');

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Shopify',
        'remove_logo' => '1',
    ]);

    expect($platform->fresh()->getRawOriginal('logo_url'))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('leaves the logo untouched when the form is saved without one', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create([
        'logo_url' => 'assets/images/shopify_logo.png',
    ]);

    $this->actingAs(superAdmin())->patch(route('super-admin.platforms.update', $platform), [
        'name' => 'Renamed',
    ]);

    expect($platform->fresh())
        ->name->toBe('Renamed')
        ->getRawOriginal('logo_url')->toBe('assets/images/shopify_logo.png');
});

it('rejects a logo that is not an image', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create();

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), [
            'name' => 'Shopify',
            'logo' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])
        ->assertSessionHasErrors('logo');
});

it('rejects a logo larger than 2 MB', function () {
    Storage::fake('public');

    $platform = EcommercePlatform::factory()->create();

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), [
            'name' => 'Shopify',
            'logo' => UploadedFile::fake()->image('huge.png')->size(3000),
        ])
        ->assertSessionHasErrors('logo');
});

it('resolves logo URLs by where the file lives', function () {
    $uploaded = EcommercePlatform::factory()->create(['logo_url' => 'platform-logos/abc.png']);
    $seeded = EcommercePlatform::factory()->create(['logo_url' => 'assets/images/shopify_logo.png']);
    $absolute = EcommercePlatform::factory()->create(['logo_url' => 'https://cdn.example.com/logo.png']);
    $none = EcommercePlatform::factory()->create(['logo_url' => null]);

    expect($uploaded->logo_url)->toBe('/storage/platform-logos/abc.png')
        ->and($seeded->logo_url)->toBe('/assets/images/shopify_logo.png')
        ->and($absolute->logo_url)->toBe('https://cdn.example.com/logo.png')
        ->and($none->logo_url)->toBeNull();
});

it('uploads a courier logo into its own directory', function () {
    Storage::fake('public');

    $courier = DeliveryCourrier::factory()->create([
        'slug' => Courier::SENDIT->value,
        'logo' => null,
    ]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.couriers.update', $courier), [
            'name' => 'Sendit',
            'logo' => UploadedFile::fake()->image('sendit.png'),
        ])
        ->assertRedirect();

    $stored = $courier->fresh()->getRawOriginal('logo');

    expect($stored)->toStartWith('courier-logos/');
    Storage::disk('public')->assertExists($stored);
});

it('never lets a platform slug be changed, even when one is posted', function () {
    $platform = EcommercePlatform::factory()->create(['slug' => 'Shopify']);

    // The slug keys the EcomPlatform enum lookups that resolve the
    // integration service, so it must survive a hand-crafted request.
    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), [
            'name' => 'Renamed',
            'slug' => 'something-else',
        ])
        ->assertRedirect();

    expect($platform->fresh()->slug)->toBe('Shopify');
});

it('rejects a platform update with no name', function () {
    $platform = EcommercePlatform::factory()->create();

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.platforms.update', $platform), ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('offers no route for creating or deleting a platform', function () {
    expect(Route::has('super-admin.platforms.store'))->toBeFalse()
        ->and(Route::has('super-admin.platforms.destroy'))->toBeFalse();
});

// ----------------------------------------------------------------- couriers

it('lists couriers with city and account counts, flagging which can sync', function () {
    $sendit = DeliveryCourrier::factory()->create([
        'name' => 'Sendit',
        'slug' => Courier::SENDIT->value,
    ]);
    DeleveryCourrierCity::factory()->count(3)->create(['courrier_id' => $sendit->id]);

    DeliveryCourrier::factory()->create([
        'name' => 'Cathedis',
        'slug' => Courier::CATHEDIS->value,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.index'))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/couriers/index')
            ->has('couriers', 2)
            // Alphabetical: Cathedis first, and it has no cities API.
            ->where('couriers.0.name', 'Cathedis')
            ->where('couriers.0.syncable', false)
            ->where('couriers.1.name', 'Sendit')
            ->where('couriers.1.syncable', true)
            ->where('couriers.1.cities_count', 3)
        );
});

it('updates a courier but leaves its slug alone', function () {
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.couriers.update', $courier), [
            'name' => 'Sendit Express',
            'description' => 'Updated.',
            'slug' => 'hijacked',
        ])
        ->assertRedirect(route('super-admin.couriers.index'));

    expect($courier->fresh())
        ->name->toBe('Sendit Express')
        ->slug->toBe(Courier::SENDIT->value);
});

it('lists a courier\'s cities, searchable by name and arabic name', function () {
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);

    DeleveryCourrierCity::factory()->create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'arabic_name' => 'الدار البيضاء',
    ]);
    DeleveryCourrierCity::factory()->create([
        'courrier_id' => $courier->id,
        'name' => 'Marrakech',
        'arabic_name' => 'مراكش',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.cities', $courier))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/couriers/cities')
            ->has('cities.data', 2)
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.cities', [$courier, 'search' => 'casa']))
        ->assertInertia(fn ($page) => $page
            ->has('cities.data', 1)
            ->where('cities.data.0.name', 'Casablanca')
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.cities', [$courier, 'search' => 'مراكش']))
        ->assertInertia(fn ($page) => $page
            ->has('cities.data', 1)
            ->where('cities.data.0.name', 'Marrakech')
        );
});

it('only shows one courier\'s cities on its own page', function () {
    $sendit = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);
    $ozon = DeliveryCourrier::factory()->create(['slug' => Courier::OZONEXPRESS->value]);

    DeleveryCourrierCity::factory()->create(['courrier_id' => $sendit->id, 'name' => 'Mine']);
    DeleveryCourrierCity::factory()->create(['courrier_id' => $ozon->id, 'name' => 'Theirs']);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.cities', $sendit))
        ->assertInertia(fn ($page) => $page
            ->has('cities.data', 1)
            ->where('cities.data.0.name', 'Mine')
        );
});

it('runs the city sync command for a courier that supports it', function () {
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('couriers:sync-cities', ['--courier' => Courier::SENDIT->value])
        ->andReturn(0);

    $this->actingAs(superAdmin())
        ->post(route('super-admin.couriers.sync-cities', $courier))
        ->assertRedirect();
});

it('refuses to sync cities for a courier with no cities API', function () {
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::CATHEDIS->value]);

    Artisan::shouldReceive('call')->never();

    $this->actingAs(superAdmin())
        ->post(route('super-admin.couriers.sync-cities', $courier))
        ->assertStatus(422);
});

// ------------------------------------------------------------ authorization

it('forbids non super admins from every catalog route', function () {
    $platform = EcommercePlatform::factory()->create();
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->get(route('super-admin.platforms.index'))->assertForbidden();
    $this->actingAs($admin)->patch(route('super-admin.platforms.update', $platform), ['name' => 'x'])->assertForbidden();
    $this->actingAs($admin)->get(route('super-admin.couriers.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('super-admin.couriers.cities', $courier))->assertForbidden();
    $this->actingAs($admin)->post(route('super-admin.couriers.sync-cities', $courier))->assertForbidden();

    expect($platform->fresh()->name)->not->toBe('x');
});

it('serves a courier\'s cities as JSON for the cities sheet', function () {
    $courier = DeliveryCourrier::factory()->create(['slug' => Courier::SENDIT->value]);

    DeleveryCourrierCity::factory()->create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);
    DeleveryCourrierCity::factory()->create(['courrier_id' => $courier->id, 'name' => 'Marrakech']);

    $this->actingAs(superAdmin())
        ->getJson(route('super-admin.couriers.cities', [$courier, 'search' => 'mar', 'per_page' => 100]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'Marrakech');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.couriers.index'))
        ->assertInertia(fn ($page) => $page->has('couriers.0.default_logo'));
});
