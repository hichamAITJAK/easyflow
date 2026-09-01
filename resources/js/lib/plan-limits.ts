/**
 * Human labels for the plan limit keys the backend accepts
 * (StorePlanRequest::LIMIT_KEYS). Keys not listed here fall back to a
 * de-underscored version of the key itself, so a new limit added on the
 * backend still renders sensibly before this map catches up.
 */
const LIMIT_LABELS: Record<string, string> = {
    max_stores: 'Stores',
    max_delivery_couriers: 'Delivery couriers',
    max_confirmation_agents: 'Confirmation agents',
    max_fulfilment_agents: 'Fulfilment agents',
    daily_orders: 'Orders per day',
};

export function limitLabel(key: string): string {
    return (
        LIMIT_LABELS[key] ??
        key.replace(/_/g, ' ').replace(/^./, (char) => char.toUpperCase())
    );
}

/** Blank means "no ceiling", which is what an absent key encodes. */
export function formatLimit(value: number | null | undefined): string {
    return value === null || value === undefined ? 'Unlimited' : String(value);
}
