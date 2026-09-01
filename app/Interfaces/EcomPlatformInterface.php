<?php

namespace App\Interfaces;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

interface EcomPlatformInterface
{
    /**
     * Get store details for the connected store.
     *
     * @return array<string, mixed>
     */
    public function getStoreDetails(): array;

    /**
     * List this store's products, mapped into the platform's product DTO.
     *
     * Deliberately unfiltered by date: the catalog is reference data that
     * order line items are matched against, and an order placed today may
     * reference a product created years ago. Only orders are date-scoped.
     *
     * @return array<int, Arrayable<string, mixed>>
     */
    public function loadProducts(): array;

    /**
     * List this store's orders, mapped into the platform's order DTO.
     *
     * `$since` asks the platform to return only orders created at or after
     * that moment, so a newly connected store doesn't pull its whole trading
     * history. Shopify and YouCan apply this server-side; Lightfunnels has no
     * documented date filter, so it ignores the hint. It is a HINT either
     * way — OrderSyncService re-applies the cutoff to whatever comes back,
     * which is what actually enforces it.
     *
     * @return array<int, Arrayable<string, mixed>>
     */
    public function loadOrders(?CarbonInterface $since = null): array;

    /**
     * Subscribe this store to the platform's order-creation webhook, so new
     * orders arrive in near-real-time instead of relying solely on the
     * nightly reconciliation sync. Implementations that have no receiving
     * endpoint wired up yet may no-op rather than throw.
     */
    public function registerOrderWebhook(): void;

    /**
     * Unsubscribe this store from the platform's order-creation webhook,
     * e.g. when the store is being disconnected/deleted. Must be called
     * while the store's credentials are still intact — implementations
     * should no-op (not throw) if there's nothing to unsubscribe from.
     */
    public function deregisterOrderWebhook(): void;
}
