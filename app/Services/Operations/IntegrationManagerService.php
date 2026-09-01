<?php

namespace App\Services\Operations;

use App\Enums\Courier;
use App\Enums\EcomPlatform;
use App\Interfaces\DeliveryCourierInterface;
use App\Interfaces\EcomPlatformInterface;
use App\Models\DeliveryAccount;
use App\Models\Store;
use App\Services\Operations\Couriers\AmeexService;
use App\Services\Operations\Couriers\ColiixService;
use App\Services\Operations\Couriers\ForceLogService;
use App\Services\Operations\Couriers\OzonExpressService;
use App\Services\Operations\Couriers\SenditService;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use App\Services\Operations\EcomPlatforms\StoreepService;
use App\Services\Operations\EcomPlatforms\WooCommerceService;
use App\Services\Operations\EcomPlatforms\YouCanService;
use InvalidArgumentException;

/**
 * Resolves the concrete integration service for an ecom platform or a
 * delivery courier, so callers can work against EcomPlatformInterface /
 * DeliveryCourierInterface without ever knowing which concrete class
 * (YouCanService, SenditService, ...) is doing the work.
 */
class IntegrationManagerService
{
    /**
     * Build the ecom platform service for the given store.
     */
    public function ecomPlatform(EcomPlatform $platform, Store $store): EcomPlatformInterface
    {
        // Every EcomPlatform case now has a service, so there is no default
        // arm left to write. A case added later with no arm here raises
        // PHP's own UnhandledMatchError, which says the same thing.
        return match ($platform) {
            EcomPlatform::YOUCAN => new YouCanService($store),
            EcomPlatform::SHOPIFY => new ShopifyService($store),
            EcomPlatform::LIGHTFUNNELS => new LightfunnelsService($store),
            EcomPlatform::STOREEP => new StoreepService($store),
            EcomPlatform::WOOCOMMERCE => new WooCommerceService($store),
        };
    }

    /**
     * Build the ecom platform service for the given store, using its own platform.
     */
    public function ecomPlatformForStore(Store $store): EcomPlatformInterface
    {
        $platform = EcomPlatform::tryFrom($store->platform->slug)
            ?? throw new InvalidArgumentException("Unknown ecom platform slug [{$store->platform->slug}].");

        return $this->ecomPlatform($platform, $store);
    }

    /**
     * Build the delivery courier service for the given courier.
     */
    public function courier(Courier $courier, ?DeliveryAccount $account = null): DeliveryCourierInterface
    {
        return match ($courier) {
            Courier::SENDIT => new SenditService($account),
            Courier::OZONEXPRESS => new OzonExpressService($account),
            Courier::COLIIX => new ColiixService($account),
            Courier::FORCELOG => new ForceLogService($account),
            Courier::AMEEX => new AmeexService($account),
            default => throw new InvalidArgumentException("No courier service is available for [{$courier->value}] yet."),
        };
    }

    /**
     * Build the delivery courier service for the given delivery account, using its own courier.
     */
    public function courierForAccount(DeliveryAccount $account): DeliveryCourierInterface
    {
        $courier = Courier::tryFrom($account->courier->slug)
            ?? throw new InvalidArgumentException("Unknown courier slug [{$account->courier->slug}].");

        return $this->courier($courier, $account);
    }
}
