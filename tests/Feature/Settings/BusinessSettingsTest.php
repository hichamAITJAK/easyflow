<?php

use App\Enums\UserRole;
use App\Models\PerformanceTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('the business settings page no longer carries performance targets', function () {
    $this->actingAs(makeBusinessUser())
        ->get(route('business.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/business')
            ->has('business')
            ->missing('targets')
            ->missing('minOrdersForEvaluation')
        );
});

test('there is no endpoint left to save business-wide targets', function () {
    $this->actingAs(makeBusinessUser())
        ->patch('/settings/business', ['min_orders_for_evaluation' => 5])
        ->assertStatus(405);

    expect(PerformanceTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('a confirmation agent cannot view business settings', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)->get(route('business.edit'))->assertForbidden();
});

test('the cleanup migration deletes business-wide targets and keeps agent ones', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $row = fn (?int $userId) => PerformanceTarget::create([
        'business_id' => $admin->business_id,
        'user_id' => $userId,
        'metric' => 'confirmation_rate',
        'target_percentage' => 70,
        'period' => 'weekly',
    ]);

    $row(null);
    $own = $row($agent->id);

    (require database_path('migrations/2026_10_05_000000_delete_business_wide_performance_targets.php'))->up();

    expect(PerformanceTarget::withoutGlobalScopes()->pluck('id')->all())->toBe([$own->id]);
});

test('an admin updates their business identity details', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => 'EasyFlow Store',
        'legal_name' => 'EasyFlow SARL',
        'ice' => '001234567000089',
        'rc' => 'RC-12345',
        'if_number' => 'IF-98765',
        'phone' => '+212 612-345-678',
        'email' => 'contact@easyflow.ma',
        'address' => '12 Rue Hassan II',
        'city' => 'Casablanca',
    ])->assertRedirect();

    $business = $admin->business->fresh();

    expect($business->name)->toBe('EasyFlow Store');
    expect($business->legal_name)->toBe('EasyFlow SARL');
    expect($business->ice)->toBe('001234567000089');
    // Normalised through PhoneNumber::format, same as user profiles.
    expect($business->phone)->toBe('0612345678');
    expect($business->city)->toBe('Casablanca');
});

test('the business edit page exposes the current details', function () {
    $admin = makeBusinessUser();
    $admin->business->update(['ice' => '001234567000089', 'city' => 'Rabat']);

    $this->actingAs($admin)
        ->get(route('business.edit'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/business')
            ->where('business.ice', '001234567000089')
            ->where('business.city', 'Rabat')
            // The slug identifies the business and stays super-admin-only.
            ->missing('business.slug')
        );
});

test('a logo upload is stored and resolves to a public url', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertRedirect();

    $business = $admin->business->fresh();
    $stored = $business->getRawOriginal('logo');

    expect($stored)->not->toBeNull();
    Storage::disk('public')->assertExists($stored);
    // The accessor hands the frontend a URL, not a disk path.
    expect($business->logo)->toBe('/storage/'.$stored);
});

test('removing a logo deletes the stored file', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $stored = $admin->business->fresh()->getRawOriginal('logo');

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'remove_logo' => '1',
    ])->assertRedirect();

    expect($admin->business->fresh()->logo)->toBeNull();
    Storage::disk('public')->assertMissing($stored);
});

test('an agent cannot change business details', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $originalName = $agent->business->name;

    $this->actingAs($agent)->post(route('business.profile.update'), [
        'name' => 'Hijacked',
    ])->assertForbidden();

    expect($agent->business->fresh()->name)->toBe($originalName);
});

test('business details validation rejects a bad email and oversized logo', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => '',
        'email' => 'not-an-email',
        'logo' => UploadedFile::fake()->create('huge.png', 4096, 'image/png'),
    ])->assertSessionHasErrors(['name', 'email', 'logo']);
});
