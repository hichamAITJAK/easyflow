<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('product sync asks YouCan to include variants and stores them', function () {
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);
    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'faizat',
        'external_store_id' => 'faizat',
        'api_credentials' => json_encode(['access_token' => 'ACCESS-1']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    // Shaped like the real response: the variant rows only come back
    // when `include=variants` is on the query.
    Http::fake(function ($request) {
        if (! str_contains($request->url(), '/products')) {
            return Http::response([], 404);
        }

        $product = [
            'id' => 'b1b98088-1096-4a65-a9d8-08395a864cf3',
            'name' => 'Robe en Denim Élégante',
            'price' => 319,
            'visibility' => true,
            'has_variants' => true,
            'variants_count' => 2,
        ];

        if (($request->data()['include'] ?? null) === 'variants') {
            $product['variants'] = [
                ['id' => 'v-1', 'variations' => ['Couleur' => 'Bleu'], 'price' => 319, 'sku' => 'ROBE-BL', 'inventory' => 4],
                ['id' => 'v-2', 'variations' => ['Couleur' => 'Noir'], 'price' => 319, 'sku' => 'ROBE-NR', 'inventory' => 0],
            ];
        }

        return Http::response([
            'data' => [$product],
            'meta' => ['pagination' => ['current_page' => 1, 'total_pages' => 1]],
        ]);
    });

    app(ProductSyncService::class)->syncStore($store);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/products')
        && ($request->data()['include'] ?? null) === 'variants');

    $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->firstOrFail();

    expect($product->variants()->count())->toBe(2)
        ->and($product->variants()->pluck('sku')->sort()->values()->all())->toBe(['ROBE-BL', 'ROBE-NR']);
});
