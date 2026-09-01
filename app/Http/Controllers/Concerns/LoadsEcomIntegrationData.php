<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\IntegrationManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared "load live data from an ecom platform" flow used by both orders and
 * products: resolve the requested platform (or every connected store, for
 * "*"), delegate to whichever service IntegrationManagerService resolves,
 * and return its DTOs as JSON — without the controller ever needing to know
 * which concrete class is doing the work.
 */
trait LoadsEcomIntegrationData
{
    /**
     * Load data for a single platform, or every connected store when the
     * platform value is "*".
     *
     * @param  'loadOrders'|'loadProducts'  $method
     */
    private function loadEcomData(Request $request, string $method): JsonResponse
    {
        $request->validate([
            'platform' => ['required', 'string'],
        ]);

        $platformValue = $request->string('platform')->toString();

        if ($platformValue === '*') {
            return $this->loadEcomDataFromAllStores($request, $method);
        }

        $platform = $this->resolvePlatform($platformValue);

        $store = Store::where('business_id', $request->user()->business_id)
            ->whereHas('platform', fn ($query) => $query->where('slug', $platform->value))
            ->firstOrFail();

        $service = (new IntegrationManagerService)->ecomPlatform($platform, $store);

        return response()->json([
            'data' => array_map(fn ($item) => $item->toArray(), $service->$method()),
        ]);
    }

    /**
     * Load data from every connected store of the current business,
     * skipping any store whose platform has no service available yet.
     *
     * @param  'loadOrders'|'loadProducts'  $method
     */
    private function loadEcomDataFromAllStores(Request $request, string $method): JsonResponse
    {
        $manager = new IntegrationManagerService;

        $stores = Store::where('business_id', $request->user()->business_id)
            ->where('connection_status', StoreConnectionStatus::CONNECTED)
            ->with('platform')
            ->get();

        $results = [];

        foreach ($stores as $store) {
            try {
                $items = $manager->ecomPlatformForStore($store)->$method();
            } catch (InvalidArgumentException) {
                continue;
            }

            $results[] = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'platform' => $store->platform->slug,
                'data' => array_map(fn ($item) => $item->toArray(), $items),
            ];
        }

        return response()->json(['data' => $results]);
    }

    /**
     * Resolve an EcomPlatform from a request value that may be a platform
     * slug/name (e.g. "YouCan") or an ecommerce_platforms.id.
     */
    private function resolvePlatform(string $value): EcomPlatform
    {
        $slug = ctype_digit($value)
            ? EcommercePlatform::find((int) $value)?->slug
            : $value;

        return EcomPlatform::tryFrom((string) $slug)
            ?? throw ValidationException::withMessages(['platform' => "Unknown or unsupported platform [{$value}]."]);
    }
}
