<?php

namespace App\Events\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an order's assigned_agent_id is set — automatic (round-robin
 * /load-based, UC-6) or manual override by Owner/Manager. The seam for
 * UC-21's "new order assigned" push notification to the agent.
 */
class OrderAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly User $agent,
    ) {}
}
