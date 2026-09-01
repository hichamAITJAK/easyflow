<?php

namespace App\Listeners\Store;

use App\Events\Store\StoreDeleting;
use App\Services\Operations\IntegrationManagerService;

/**
 * Unsubscribes a store's platform webhook before the store is deleted.
 *
 * Deliberately NOT queued (unlike RegisterOrderWebhookOnConnected) — this
 * must complete while the store's credentials still exist, and a queued
 * listener could run after the store row (and thus its api_credentials)
 * is already gone.
 */
class UnsubscribeWebhookOnDeleting
{
    public function __construct(private readonly IntegrationManagerService $manager) {}

    public function handle(StoreDeleting $event): void
    {
        $this->manager->ecomPlatformForStore($event->store)->deregisterOrderWebhook();
    }
}
