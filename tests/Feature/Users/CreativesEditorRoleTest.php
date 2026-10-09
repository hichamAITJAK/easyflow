<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin creates a creatives editor from the Team page without any pay or scope rows', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->get(route('users.create', ['role' => 'creatives_editor']))
        ->assertInertia(fn ($page) => $page->where('role', 'creatives_editor'));

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Edit Or',
        'email' => 'editor@example.com',
        'role' => 'creatives_editor',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

    $editor = User::where('email', 'editor@example.com')->firstOrFail();
    expect($editor->role)->toBe(UserRole::CREATIVES_EDITOR)
        ->and($editor->business_id)->toBe($admin->business_id)
        ->and($editor->commissionRules()->count())->toBe(0)
        ->and($editor->agentScopes()->count())->toBe(0);

    // Editing keeps the role and still needs nothing else.
    $this->actingAs($admin)->patch(route('users.update', $editor), [
        'name' => 'Edit Or',
        'email' => 'editor@example.com',
        'role' => 'creatives_editor',
        'status' => 'active',
    ])->assertSessionHasNoErrors();
});

test('a creatives editor lands on Creatives and is kept out of operations', function () {
    $admin = makeBusinessUser();
    $editor = User::factory()->creativesEditor()->create(['business_id' => $admin->business_id]);

    $this->post(route('login.store'), ['email' => $editor->email, 'password' => 'password'])
        ->assertRedirect(route('creatives.index'));

    $this->actingAs($editor)->get(route('dashboard'))->assertRedirect(route('creatives.index'));
    $this->actingAs($editor)->get(route('creatives.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('creatives/index'));

    $this->actingAs($editor)->get(route('orders.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('products.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('users.index'))->assertForbidden();

    // Admins open the module; confirmation agents do not.
    $this->actingAs($admin)->get(route('creatives.index'))->assertOk();
    $confirmer = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($confirmer)->get(route('creatives.index'))->assertForbidden();
});
