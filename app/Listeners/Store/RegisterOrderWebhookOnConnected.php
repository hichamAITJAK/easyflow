<?php

namespace App\Listeners\Store;

use App\Events\Store\StoreConnected;
use App\Services\Operations\IntegrationManagerService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Subscribes a newly connected store to its platform's order-creation
 * webhook. Delegates to the platform's own EcomPlatformInterface
 * implementation (resolved via IntegrationManagerService) rather than
 * branching on provider name here — per the integration architecture's
 * rule that only the Manager's resolution method may know which concrete
 * connection service to use.
 */
class RegisterOrderWebhookOnConnected implements ShouldQueue
{
    public function __construct(private readonly IntegrationManagerService $manager) {}

    public function handle(StoreConnected $event): void
    {
        $this->manager->ecomPlatformForStore($event->store)->registerOrderWebhook();
    }
}
