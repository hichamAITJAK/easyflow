<?php

namespace App\Http\Controllers\Products;

use App\Enums\StoreConnectionStatus;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Concerns\LoadsEcomIntegrationData;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Operations\Products\ProductImageWriter;
use App\Services\Operations\Products\ProductSyncService;
use App\Services\Operations\Products\ProductVariantWriter;
use App\Services\PostHogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use BuildsTableQuery;
    use LoadsEcomIntegrationData;
    use ScopesAgentAccess;

    /**
     * Columns the products table may be sorted by, keyed to their allowed
     * query-string `sort` value.
     */
    private const SORTABLE = ['name', 'price', 'is_active', 'updated_at'];

    /**
     * Columns searched by the `search` query param.
     */
    private const SEARCHABLE = ['name'];

    /**
     * Display the products list with metric cards.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = $user->business_id;
        $scopedStoreIds = $this->scopedStoreIds($user);

        $scopedProducts = fn () => $this->applyProductScope(Product::where('business_id', $businessId), $user);

        $query = $scopedProducts()
            ->with('store')
            ->withSum('variants', 'inventory_quantity')
            ->when($this->resolveIdList($request, 'store_ids', 'store_id'), fn ($query, array $storeIds) => $query->whereIn('store_id', $storeIds))
            ->when($request->input('price_min'), fn ($query, $price) => $query->where('price', '>=', $price))
            ->when($request->input('price_max'), fn ($query, $price) => $query->where('price', '<=', $price));

        $query = $this->applySearch($query, $request, self::SEARCHABLE);
        $query = $this->applySort($query, $request, self::SORTABLE, default: 'created_at');

        $products = $query->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('products/index', [
            'products' => $products,
            'metrics' => [
                'total' => $scopedProducts()->count(),
                'active' => $scopedProducts()->where('is_active', true)->count(),
                'inactive' => $scopedProducts()->where('is_active', false)->count(),
                'test' => $scopedProducts()->where('is_test', true)->count(),
            ],
            'filters' => [
                ...$request->only(['search', 'sort', 'direction', 'per_page', 'price_min', 'price_max']),
                'store_ids' => $this->idListParam($request, 'store_ids', 'store_id'),
            ],
            // connection_status rides along so the sync picker can say which
            // stores it can actually pull from — syncing skips anything not
            // connected, and a picker that offered them silently would
            // report "0 products synced" with no reason given.
            'stores' => Store::where('business_id', $businessId)
                ->when($scopedStoreIds !== null, fn ($query) => $query->whereIn('id', $scopedStoreIds))
                ->orderBy('name')
                ->get(['id', 'name', 'connection_status']),
        ]);
    }

    /**
     * Show the form for creating a new product manually.
     */
    public function create(): Response
    {
        return Inertia::render('products/create');
    }

    /**
     * Show the form for editing an existing product.
     */
    public function edit(Product $product): Response
    {
        return Inertia::render('products/edit', [
            'product' => $product,
            // Images carry both the raw storage path (what the form
            // resubmits on save) and the resolved url (what the gallery
            // displays) — ProductImage::path only exposes the resolved
            // form, so it can't be reused as-is for round-tripping.
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'path' => $image->getRawOriginal('path'),
                'url' => $image->path,
            ]),
            'variants' => $this->variantsWithOptions($product),
        ]);
    }

    /**
     * A product's variants with their option name/value pairs resolved —
     * the shape ProductForm's editor expects (see variantSignature/
     * editorStateFromVariants on the frontend), shared between the edit
     * page and the on-demand variants() JSON endpoint below.
     *
     * @return array<int, array{id: int, sku: string|null, image: string, price: float|null, inventory_quantity: int|null, is_available: bool, options: array<int, array{name: string, value: string}>}>
     */
    private function variantsWithOptions(Product $product): array
    {
        return $product->variants()
            ->with('optionValues.option')
            ->orderBy('position')
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'image' => $variant->image,
                'price' => $variant->price,
                'inventory_quantity' => $variant->inventory_quantity,
                'is_available' => $variant->is_available,
                'options' => $variant->optionValues->map(fn (ProductOptionValue $value) => [
                    'name' => $value->option->name,
                    'value' => $value->value,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Load products live from an ecom platform for the current business,
     * delegating to whichever platform service IntegrationManagerService
     * resolves — this controller never needs to know which concrete class
     * (YouCanService, ShopifyService, ...) ends up doing the work.
     *
     * Passing `platform=*` loads products from every store the business has
     * connected, instead of a single platform.
     */
    public function loadProducts(Request $request): JsonResponse
    {
        return $this->loadEcomData($request, 'loadProducts');
    }

    /**
     * Load products live from an ecom platform and persist them, upserting
     * on (store_id, external_product_id) so re-syncing never duplicates rows.
     */
    public function sync(Request $request, ProductSyncService $productSync, PostHogService $posthog): RedirectResponse
    {
        $request->validate([
            'platform' => ['required', 'string'],
            // Scopes the sync to one store. Absent (or '*') means every
            // connected store, which stays the default.
            'store_id' => ['nullable', 'integer'],
        ]);

        $platformValue = $request->string('platform')->toString();
        $storeId = $request->integer('store_id');

        $stores = Store::where('business_id', $request->user()->business_id)
            ->where('connection_status', StoreConnectionStatus::CONNECTED)
            // business_id above already scopes this to the tenant, so a
            // forged store_id can only ever narrow to a store they own.
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->when(
                $platformValue !== '*',
                fn ($query) => $query->whereHas(
                    'platform',
                    fn ($platformQuery) => $platformQuery->where('slug', $this->resolvePlatform($platformValue)->value)
                )
            )
            ->with('platform')
            ->get();

        $synced = 0;

        foreach ($stores as $store) {
            $synced += $productSync->syncStore($store);
        }

        // PostHog: Track products sync
        $posthog->capture((string) $request->user()->id, 'products_synced', [
            'platform' => $platformValue,
            'synced_count' => $synced,
            'business_id' => $request->user()->business_id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count product synced.|:count products synced.', $synced, ['count' => $synced]),
        ]);

        return back(fallback: route('products.index'));
    }

    /**
     * Create a manually-added product (store_id null — not synced from any
     * connected store), fully customizable from the start, along with its
     * option/variant graph.
     */
    public function store(StoreProductRequest $request, ProductVariantWriter $variantWriter, ProductImageWriter $imageWriter): RedirectResponse
    {
        $product = Product::create([
            ...$request->safe()->except(['options', 'variants', 'images']),
            'business_id' => $request->user()->business_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $variantWriter->write($product, $request->input('options', []), $request->input('variants', []));
        $imageWriter->write($product, $request->input('images', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return back(fallback: route('products.index'));
    }

    /**
     * Update a product. A manually-added product accepts every field (and
     * its variant graph); a store-synced product only accepts sku, since
     * the platform owns the rest — including its variants — and would
     * overwrite it on the next sync anyway (see UpdateProductRequest).
     */
    public function update(UpdateProductRequest $request, Product $product, ProductVariantWriter $variantWriter, ProductImageWriter $imageWriter): RedirectResponse
    {
        $product->update($request->safe()->except(['options', 'variants', 'images', 'variant_skus']));

        if ($product->store_id === null) {
            $variantWriter->write($product, $request->input('options', []), $request->input('variants', []));
            $imageWriter->write($product, $request->input('images', []));
        } else {
            $this->updateSyncedVariantSkus($product, $request->validated('variant_skus') ?? []);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return back(fallback: route('products.index'));
    }

    /**
     * Relabel a synced product's variant SKUs. Only the SKU is writable —
     * every other variant field is platform-owned and would be overwritten
     * on the next sync anyway.
     *
     * Writes go through the product's own variants() relation, so a forged
     * id in the payload matches nothing rather than relabelling a variant
     * on some other product (or some other business's product).
     *
     * @param  array<int|string, string|null>  $skus
     */
    private function updateSyncedVariantSkus(Product $product, array $skus): void
    {
        if ($skus === []) {
            return;
        }

        $variants = $product->variants()
            ->whereIn('id', array_map('intval', array_keys($skus)))
            ->get();

        foreach ($variants as $variant) {
            $sku = $skus[$variant->id] ?? $skus[(string) $variant->id] ?? null;

            // Blanking the field clears the mapping rather than storing an
            // empty string, matching how the product-level sku is handled.
            $variant->update(['sku' => $sku !== null && $sku !== '' ? $sku : null]);
        }
    }

    /**
     * List a product's variants (with their resolved option name/value
     * pairs), for the product detail dialog — fetched on demand rather
     * than eager-loaded on the index listing, since most rows in the table
     * are never opened.
     */
    public function variants(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        abort_unless($product->business_id === $user->business_id, 403);

        // Agents reach this route too, so the tenant check above isn't
        // enough on its own — re-apply the same AgentScope filter the list
        // uses, or a scoped agent could read any product in the business by
        // guessing its ID.
        abort_unless(
            $this->applyProductScope(Product::whereKey($product->getKey()), $user)->exists(),
            403,
        );

        return response()->json(['variants' => $this->variantsWithOptions($product)]);
    }

    /**
     * Upload an image for a manual product's variant, ahead of the product
     * itself being saved (the variant editor needs a URL to store in its
     * draft as soon as a file is dropped, before the surrounding form is
     * submitted). Returns the stored path for ProductVariant::image to
     * resolve into a public URL, same as user avatars.
     */
    public function uploadVariantImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $path = $request->file('image')->store('variant-images', 'public');

        return response()->json(['path' => $path, 'url' => '/storage/'.$path]);
    }

    /**
     * Upload one image for a manual product's gallery, ahead of the product
     * itself being saved (the gallery editor needs a URL to add to its
     * ordered list as soon as a file is dropped, before the surrounding
     * form is submitted). Returns the stored path for ProductImage::path to
     * resolve into a public URL, same as user avatars.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $path = $request->file('image')->store('product-images', 'public');

        return response()->json(['path' => $path, 'url' => '/storage/'.$path]);
    }

    /**
     * Soft-delete a manually-added product. A store-synced product
     * (store_id set) is platform-owned — deleting it here would just have
     * it reappear on the next sync, so it can only be unlisted from the
     * connected store itself.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->business_id === $request->user()->business_id, 403);
        abort_if($product->store_id !== null, 403, 'Synced products can only be removed from their connected store.');

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return back(fallback: route('products.index'));
    }

    /**
     * Toggle a product's test flag. This is local-only bookkeeping — test
     * products are never reported back to the connected ecom platform.
     */
    public function markTest(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->business_id === $request->user()->business_id, 403);

        $product->update(['is_test' => ! $product->is_test]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $product->is_test ? __('Product marked as test.') : __('Product unmarked as test.'),
        ]);

        return back();
    }
}
