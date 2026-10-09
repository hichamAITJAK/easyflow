<?php

use App\Enums\ContentRequestStatus;
use App\Enums\CreativeProductStatus;
use App\Models\CommissionLedgerEntry;
use App\Models\ContentRequest;
use App\Models\CreativeProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creativesSetup(): array
{
    $admin = makeBusinessUser();
    $editor = User::factory()->creativesEditor()->create(['business_id' => $admin->business_id, 'name' => 'Walid E.']);
    $other = User::factory()->creativesEditor()->create(['business_id' => $admin->business_id, 'name' => 'Imane C.']);

    return [$admin, $editor, $other];
}

test('an admin creates a product and orders content: one sent row per editor', function () {
    [$admin, $editor, $other] = creativesSetup();

    $this->actingAs($admin)->post(route('creatives.products.store'), [
        'name' => 'Neck Cloud Pillow',
        'kind' => 'single',
        'links' => ['https://trenchies.ma/products/neck-cloud'],
        'description' => 'Neck pain relief angle',
        'editor_ids' => [$editor->id],
    ])->assertSessionHasNoErrors();

    $product = CreativeProduct::withoutGlobalScopes()->firstOrFail();
    expect($product->status)->toBe(CreativeProductStatus::TESTING)
        ->and($product->editors()->pluck('users.id')->all())->toBe([$editor->id]);

    $this->actingAs($admin)->post(route('creatives.requests.store'), [
        'product_id' => $product->id,
        'editor_ids' => [$editor->id, $other->id],
        'items' => [
            ['type' => 'video', 'count' => 2, 'directions' => ['Darija hook', '']],
            ['type' => 'static', 'count' => 1, 'directions' => []],
        ],
        'note' => 'Push the pain angle',
    ])->assertSessionHasNoErrors();

    $requests = ContentRequest::withoutGlobalScopes()->get();
    expect($requests)->toHaveCount(2)
        ->and($requests->pluck('status')->unique()->all())->toBe([ContentRequestStatus::SENT])
        ->and($requests->first()->items()->count())->toBe(2)
        ->and($requests->first()->items()->where('type', 'video')->first()->directions)->toBe(['Darija hook', '']);

    // Admin page lists the product and the two queue rows.
    $this->actingAs($admin)->get(route('creatives.index'))
        ->assertInertia(fn ($page) => $page
            ->component('creatives/admin')
            ->has('products', 1)
            ->has('queue', 2)
            ->has('editors', 2)
            ->where('queue.0.status', 'sent'));

    // A non-editor cannot be assigned; a foreign editor neither.
    $this->actingAs($admin)->post(route('creatives.requests.store'), [
        'product_id' => $product->id,
        'editor_ids' => [$admin->id],
        'items' => [['type' => 'video', 'count' => 1]],
    ])->assertSessionHasErrors('editor_ids.0');
});

test('the queue state machine: push → edits → resubmit → validate writes the pay row', function () {
    [$admin, $editor] = creativesSetup();
    $product = CreativeProduct::create([
        'business_id' => $admin->business_id, 'name' => 'Posture Pro', 'kind' => 'single',
        'links' => ['https://x.ma/p'], 'status' => CreativeProductStatus::ACTIVE,
    ]);
    $product->editors()->attach($editor->id, ['business_id' => $admin->business_id]);

    $this->actingAs($admin)->post(route('creatives.requests.store'), [
        'product_id' => $product->id, 'editor_ids' => [$editor->id],
        'items' => [['type' => 'video', 'count' => 4]],
    ]);
    $request = ContentRequest::withoutGlobalScopes()->firstOrFail();

    // Admin may edit and the editor may not push without a link.
    $this->actingAs($admin)->put(route('creatives.requests.update', $request), [
        'items' => [['type' => 'video', 'count' => 3, 'directions' => ['a', 'b', 'c']]], 'note' => 'edited',
    ])->assertSessionHasNoErrors();
    expect($request->fresh()->items()->first()->count)->toBe(3)->and($request->fresh()->rev)->toBe(1);

    $this->actingAs($editor)->post(route('creatives.requests.push', $request), ['note' => 'done'])
        ->assertSessionHasErrors('drive_url');

    $this->actingAs($editor)->post(route('creatives.requests.push', $request), [
        'drive_url' => 'https://drive.google.com/x', 'note' => 'First batch',
    ])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(ContentRequestStatus::RETURNED);

    // Once returned, the admin can no longer edit or cancel it.
    $this->actingAs($admin)->delete(route('creatives.requests.destroy', $request))->assertForbidden();

    // Request edits: rev bumps, points stored, editor resubmits without a rev change.
    $this->actingAs($admin)->post(route('creatives.requests.edits', $request), ['points' => ['logo too small', ' ']])
        ->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(ContentRequestStatus::EDITS)
        ->and($request->rev)->toBe(2)
        ->and($request->direction_points)->toBe(['logo too small']);

    $this->actingAs($editor)->post(route('creatives.requests.push', $request), ['drive_url' => 'https://drive.google.com/v2'])
        ->assertSessionHasNoErrors();
    expect($request->fresh()->rev)->toBe(2)->and($request->fresh()->status)->toBe(ContentRequestStatus::RETURNED);

    // Validate: ledger row, product counters, history on the page.
    $this->actingAs($admin)->post(route('creatives.requests.validate', $request), ['amount_mad' => 150])
        ->assertSessionHasNoErrors();

    $entry = CommissionLedgerEntry::withoutGlobalScopes()->where('content_request_id', $request->id)->firstOrFail();
    expect($entry->user_id)->toBe($editor->id)
        ->and($entry->entry_type)->toBe('creative')
        ->and((float) $entry->amount)->toBe(150.0)
        ->and($entry->description)->toBe('Posture Pro · Videos ×3')
        ->and($product->fresh()->works_count)->toBe(3)
        ->and($product->fresh()->last_push_at)->not->toBeNull();

    $this->actingAs($admin)->get(route('creatives.index'))
        ->assertInertia(fn ($page) => $page
            ->has('queue', 0)
            ->where('products.0.history.0.amount', 150)
            ->where('commissions.pending', 150)
            ->where('commissions.rows.0.label', 'Posture Pro · Videos ×3'));

    // It shows up on the shared Commissions page for both of them.
    $this->actingAs($editor)->get(route('commission-entries.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1)->where('entries.data.0.description', 'Posture Pro · Videos ×3')->etc());

    // Validating twice is refused.
    $this->actingAs($admin)->post(route('creatives.requests.validate', $request), ['amount_mad' => 1])->assertForbidden();
});

test('editors only see their own requests and self-push active products they are assigned to', function () {
    [$admin, $editor, $other] = creativesSetup();
    $active = CreativeProduct::create(['business_id' => $admin->business_id, 'name' => 'Active', 'kind' => 'single', 'links' => ['https://x.ma/a'], 'status' => CreativeProductStatus::ACTIVE]);
    $testing = CreativeProduct::create(['business_id' => $admin->business_id, 'name' => 'Testing', 'kind' => 'single', 'links' => ['https://x.ma/t'], 'status' => CreativeProductStatus::TESTING]);
    $active->editors()->attach($editor->id, ['business_id' => $admin->business_id]);
    $testing->editors()->attach($editor->id, ['business_id' => $admin->business_id]);

    $this->actingAs($admin)->post(route('creatives.requests.store'), [
        'product_id' => $testing->id, 'editor_ids' => [$other->id], 'items' => [['type' => 'static', 'count' => 2]],
    ]);
    $othersRequest = ContentRequest::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($editor)->get(route('creatives.index'))
        ->assertInertia(fn ($page) => $page
            ->component('creatives/editor')
            ->has('products', 1)
            ->where('products.0.name', 'Active')
            ->has('requests', 0));

    $this->actingAs($editor)->post(route('creatives.requests.push', $othersRequest), ['drive_url' => 'https://d.com/x'])->assertForbidden();

    $this->actingAs($editor)->post(route('creatives.pushes.store'), [
        'product_id' => $testing->id, 'items' => [['type' => 'video', 'count' => 2]], 'drive_url' => 'https://d.com/y',
    ])->assertStatus(422);

    $this->actingAs($editor)->post(route('creatives.pushes.store'), [
        'product_id' => $active->id, 'items' => [['type' => 'video', 'count' => 2]], 'drive_url' => 'https://d.com/y', 'note' => 'daily',
    ])->assertSessionHasNoErrors();

    $push = ContentRequest::withoutGlobalScopes()->where('origin', 'editor')->firstOrFail();
    expect($push->status)->toBe(ContentRequestStatus::RETURNED)->and($push->editor_id)->toBe($editor->id);

    // Lifecycle: archive, retest, reactivate — admin only.
    $this->actingAs($admin)->put(route('creatives.products.status', $active), ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect($active->fresh()->status)->toBe(CreativeProductStatus::INACTIVE);
    $this->actingAs($editor)->put(route('creatives.products.status', $active), ['status' => 'active'])->assertForbidden();
});
