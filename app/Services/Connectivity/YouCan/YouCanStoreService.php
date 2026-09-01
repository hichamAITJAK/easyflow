<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles all YouCan store-related API calls.
 *
 * Covers: store details, config, profits, packs, and support.
 * Also covers seller-level actions: listing all stores, registering,
 * updating, deleting, and login activity.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class YouCanStoreService extends YouCanHttpClient
{
    // ----------------------------------------------------------------
    // Store (current active store)
    // ----------------------------------------------------------------

    /**
     * Get the details of the currently authenticated store.
     *
     * @return array{id: string, name: string, domain: string, ...}
     */
    public function getDetails(): array
    {
        $response = $this->get('/me');

        return [
            'id' => $response['id'],
            'name' => $response['name'],
            'domain' => $response['domain'],
        ] + $response;
    }

    /**
     * Get store configuration settings.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->get('/store/config');
    }

    /**
     * Get store profits/revenue summary.
     *
     * @return array<string, mixed>
     */
    public function getProfits(): array
    {
        return $this->get('/store/profits');
    }

    /**
     * Request support help for the current store.
     *
     * @param  string  $message  Support message to send
     * @return array<string, mixed>
     */
    public function requestSupportHelp(string $message): array
    {
        return $this->post('/store/support', [
            'message' => $message,
        ]);
    }

    // ----------------------------------------------------------------
    // Store Packs
    // ----------------------------------------------------------------

    /**
     * List available subscription packs.
     *
     * @return array<string, mixed>
     */
    public function listPacks(): array
    {
        return $this->get('/store/packs');
    }

    /**
     * Upgrade the store to a specific pack.
     *
     * @param  string  $packId  The target pack ID
     * @return array<string, mixed>
     */
    public function upgradePack(string $packId): array
    {
        return $this->post('/store/packs/upgrade', [
            'pack_id' => $packId,
        ]);
    }

    // ----------------------------------------------------------------
    // Seller (multi-store management)
    // ----------------------------------------------------------------

    /**
     * List all stores belonging to the authenticated seller.
     *
     * @return array<string, mixed>
     */
    public function listSellerStores(): array
    {
        return $this->get('/seller/stores');
    }

    /**
     * Register a new store under the authenticated seller.
     *
     * @param  array<string, mixed>  $data  Store registration payload
     * @return array<string, mixed>
     */
    public function registerStore(array $data): array
    {
        return $this->post('/seller/stores', $data);
    }

    /**
     * Update store information.
     *
     * @param  string  $storeId  The store to update
     * @param  array<string, mixed>  $data  Fields to update
     * @return array<string, mixed>
     */
    public function updateStoreInfo(string $storeId, array $data): array
    {
        return $this->put("/seller/stores/{$storeId}", $data);
    }

    /**
     * Delete a store (initiates deletion).
     *
     * @param  string  $storeId  The store to delete
     * @return array<string, mixed>
     */
    public function deleteStore(string $storeId): array
    {
        return $this->delete("/seller/stores/{$storeId}");
    }

    /**
     * Cancel a pending store deletion.
     *
     * @param  string  $storeId  The store whose deletion to cancel
     * @return array<string, mixed>
     */
    public function cancelStoreDeletion(string $storeId): array
    {
        return $this->post("/seller/stores/{$storeId}/cancel-deletion");
    }

    /**
     * Get recent login activities for the seller account.
     *
     * @return array<string, mixed>
     */
    public function getRecentLoginActivities(): array
    {
        return $this->get('/seller/login-activities');
    }
}
