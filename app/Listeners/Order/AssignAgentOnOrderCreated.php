<?php

namespace App\Listeners\Order;

use App\Enums\OrderConfirmationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\Order\OrderCreated;
use App\Models\AgentScope;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Support\Collection;

/**
 * Auto-assigns a newly created order to a confirmation agent (UC-6),
 * load-based: the eligible agent with the fewest orders assigned today
 * gets it, ties broken by user id for determinism.
 *
 * Eligibility (v1): an active confirmation agent who either has no
 * AgentScope rows at all (whole-business access, same "empty scope = whole
 * business" rule ScopesAgentAccess already applies on the read side) or has
 * a store-scope row for this order's store. Product-level scoping isn't
 * evaluated yet since a manually created order has no line items at the
 * moment OrderCreated fires — this is a first version, not the final rule
 * set from PRD section 6.2.
 *
 * Runs synchronously: the order must already show as assigned (or visibly
 * unassigned) the moment it reaches any list, not after a queue cycle.
 */
class AssignAgentOnOrderCreated
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->assigned_agent_id !== null || $order->confirmation_status !== OrderConfirmationStatus::NEW) {
            return;
        }

        $agent = $this->pickAgent($order);

        if ($agent === null) {
            return;
        }

        $this->orders->assign($order, $agent->id);
    }

    private function pickAgent(Order $order): ?User
    {
        $eligibleAgentIds = $this->eligibleAgentIds($order);

        if ($eligibleAgentIds->isEmpty()) {
            return null;
        }

        $loadByAgentId = Order::where('business_id', $order->business_id)
            ->whereIn('assigned_agent_id', $eligibleAgentIds)
            ->whereDate('created_at', today())
            ->selectRaw('assigned_agent_id, count(*) as aggregate')
            ->groupBy('assigned_agent_id')
            ->pluck('aggregate', 'assigned_agent_id');

        $agentId = $eligibleAgentIds
            ->sortBy(fn (int $id) => [(int) ($loadByAgentId[$id] ?? 0), $id])
            ->first();

        return User::find($agentId);
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleAgentIds(Order $order): Collection
    {
        $agentIds = User::where('business_id', $order->business_id)
            ->where('role', UserRole::CONFIRMATION_AGENT)
            ->where('status', UserStatus::ACTIVE)
            ->pluck('id');

        $storeScopedAgentIds = AgentScope::where('business_id', $order->business_id)
            ->whereNotNull('store_id')
            ->pluck('user_id')
            ->unique();

        $eligibleStoreScopedAgentIds = AgentScope::where('business_id', $order->business_id)
            ->where('store_id', $order->store_id)
            ->pluck('user_id')
            ->unique();

        return $agentIds->filter(
            fn (int $agentId) => ! $storeScopedAgentIds->contains($agentId)
                || $eligibleStoreScopedAgentIds->contains($agentId)
        )->values();
    }
}
