import type {
    OrderCancelReason,
    OrderConfirmationStatus,
    OrderDeliveryStatus,
    OrderQueueBucket,
    OrderReturnReason,
} from '@/types';

export const confirmationStatusLabels: Record<OrderConfirmationStatus, string> =
    {
        new: 'New',
        assigned: 'Assigned',
        confirmed: 'Confirmed',
        confirmed_followup: 'Confirmed (follow-up)',
        callback: 'Callback',
        // "Fake" alone is an adjective with no object — as a button it reads as
        // an instruction to fake something, and in "Marked Fake" it reads as a
        // typo. Naming the object fixes both without changing the enum value.
        fake: 'Fake order',
        voicemail: 'Voicemail',
        no_answer: 'No answer',
        busy: 'Busy',
        whatsapp_sent: 'WhatsApp sent',
        cancelled: 'Cancelled',
        submitted_to_courier: 'Submitted to courier',
        test_completed: 'Test completed',
    };

export const confirmationStatusVariants: Record<
    OrderConfirmationStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    new: 'secondary',
    assigned: 'outline',
    confirmed: 'default',
    confirmed_followup: 'default',
    callback: 'outline',
    fake: 'destructive',
    voicemail: 'outline',
    no_answer: 'destructive',
    busy: 'outline',
    whatsapp_sent: 'outline',
    cancelled: 'destructive',
    submitted_to_courier: 'default',
    test_completed: 'secondary',
};

export const deliveryStatusLabels: Record<OrderDeliveryStatus, string> = {
    awaiting_pickup: 'Awaiting pickup',
    ready_for_pickup: 'Ready for pickup',
    in_transit: 'In transit',
    out_for_delivery: 'Out for delivery',
    postponed: 'Postponed',
    changed: 'Changed',
    delivery_attempt_failed: 'Delivery attempt failed',
    refused: 'Refused',
    delivered: 'Delivered',
    returned_in_transit: 'Returned (in transit)',
    return_received: 'Return received',
    cancelled_at_courier: 'Cancelled at courier',
};

export const deliveryStatusVariants: Record<
    OrderDeliveryStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    awaiting_pickup: 'secondary',
    ready_for_pickup: 'outline',
    in_transit: 'outline',
    out_for_delivery: 'outline',
    postponed: 'outline',
    changed: 'outline',
    delivery_attempt_failed: 'destructive',
    refused: 'destructive',
    delivered: 'default',
    returned_in_transit: 'destructive',
    return_received: 'destructive',
    cancelled_at_courier: 'destructive',
};

/**
 * Soft badge colors grouped by semantic meaning (neutral / info / warning /
 * success / danger / returned), so statuses that mean roughly "the same
 * kind of thing" read as the same color at a glance — 24 distinct hues
 * would be noise, not signal.
 */
const badgeColors = {
    neutral:
        'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-300',
    info: 'border-blue-200 bg-blue-100 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/40 dark:text-blue-400',
    warning:
        'border-amber-200 bg-amber-100 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-400',
    success:
        'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-400',
    danger: 'border-red-200 bg-red-100 text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-400',
    returned:
        'border-violet-200 bg-violet-100 text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/40 dark:text-violet-400',
} as const;

/**
 * The queue's bucket chips read as one horizontal run, so they carry the
 * semantic hue as a solid dot rather than as a filled badge: five tinted
 * pills side by side would compete with the order cards below them, and the
 * selected chip's own background would overwrite whichever tint it had. The
 * dot survives selection, so a bucket's identity is constant whether or not
 * it's the active one.
 *
 * Hues match `badgeColors` above — "Confirmed" here is the same green as a
 * confirmed order's badge on the card — so the filter and the thing it
 * filters agree. `all` is intentionally uncolored: it's a scope, not a
 * status, and giving it a hue would imply a meaning it doesn't have.
 *
 * Each theme takes its own step rather than one shared value: measured
 * against the card, the -500 hues land at 2.1-2.6:1 on the light canvas
 * (amber worst), while the -600 steps drop to ~3.2-3.4:1 on the dark one.
 * Both directions clear the 3:1 floor a non-text indicator needs.
 */
export const bucketDotColors: Record<OrderQueueBucket, string | null> = {
    all: null,
    new: 'bg-slate-500 dark:bg-slate-400',
    follow_up: 'bg-amber-600 dark:bg-amber-500',
    confirmed: 'bg-emerald-600 dark:bg-emerald-500',
    shipped: 'bg-blue-600 dark:bg-blue-500',
};

/**
 * Solid-dot equivalents of `badgeColors`, for lists where a filled pill on
 * every row would read as noise — the status picker stacks eleven options,
 * and eleven tinted bars would fight both each other and the selected-row
 * highlight behind them. The dot carries the same semantic hue at a fraction
 * of the ink.
 *
 * Same per-theme steps as `bucketDotColors`, for the same reason: the -600
 * steps clear 3:1 on the light surface and the -500s clear it on the dark
 * one, which is the floor a non-text indicator has to meet. Color is never
 * the only carrier here — every row still shows its full text label.
 */
const dotColors = {
    neutral: 'bg-slate-500 dark:bg-slate-400',
    info: 'bg-blue-600 dark:bg-blue-500',
    warning: 'bg-amber-600 dark:bg-amber-500',
    success: 'bg-emerald-600 dark:bg-emerald-500',
    danger: 'bg-red-600 dark:bg-red-500',
    returned: 'bg-violet-600 dark:bg-violet-500',
} as const;

/** Hues mirror `confirmationStatusColors` so a picker row and the badge it produces agree. */
export const confirmationStatusDotColors: Record<
    OrderConfirmationStatus,
    string
> = {
    new: dotColors.neutral,
    assigned: dotColors.info,
    confirmed: dotColors.success,
    confirmed_followup: dotColors.warning,
    callback: dotColors.info,
    fake: dotColors.danger,
    voicemail: dotColors.warning,
    no_answer: dotColors.warning,
    busy: dotColors.warning,
    whatsapp_sent: dotColors.info,
    cancelled: dotColors.danger,
    submitted_to_courier: dotColors.success,
    test_completed: dotColors.neutral,
};

export const confirmationStatusColors: Record<OrderConfirmationStatus, string> =
    {
        new: badgeColors.neutral,
        assigned: badgeColors.info,
        confirmed: badgeColors.success,
        confirmed_followup: badgeColors.warning,
        callback: badgeColors.info,
        fake: badgeColors.danger,
        voicemail: badgeColors.warning,
        no_answer: badgeColors.warning,
        busy: badgeColors.warning,
        whatsapp_sent: badgeColors.info,
        cancelled: badgeColors.danger,
        submitted_to_courier: badgeColors.success,
        test_completed: badgeColors.neutral,
    };

export const deliveryStatusColors: Record<OrderDeliveryStatus, string> = {
    awaiting_pickup: badgeColors.info,
    ready_for_pickup: badgeColors.info,
    in_transit: badgeColors.info,
    out_for_delivery: badgeColors.info,
    postponed: badgeColors.warning,
    changed: badgeColors.warning,
    delivery_attempt_failed: badgeColors.warning,
    refused: badgeColors.danger,
    delivered: badgeColors.success,
    returned_in_transit: badgeColors.returned,
    return_received: badgeColors.returned,
    cancelled_at_courier: badgeColors.danger,
};

/**
 * Mirrors App\Enums\OrderCancelReason. `other` is the only code that also
 * requires a free-text note (UC-9) — enforced by the picker dialog, not by
 * this label map.
 */
export const cancellationReasonLabels: Record<OrderCancelReason, string> = {
    client_unreachable: 'No answer after max attempts',
    client_changed_mind: 'Client changed their mind',
    price_too_high: 'Price too high',
    found_cheaper: 'Found cheaper elsewhere',
    duplicate_order: 'Duplicate order',
    blacklisted_client: 'Blacklisted client',
    invalid_address: 'Invalid address',
    invalid_phone: 'Invalid phone number',
    out_of_stock: 'Out of stock',
    fraud_suspected: 'Fraud suspected',
    agent_error: 'Agent error',
    other: 'Other',
};

/**
 * Twelve equal-weight radios in one flat run is the densest decision in the
 * whole flow, and it sits at the one irreversible moment. These are the same
 * codes, grouped by what the agent actually learned on the call — the same
 * split CONFIRMATION_STATUS_GROUPS already applies to statuses.
 */
export const CANCEL_REASON_GROUPS: {
    label: string;
    reasons: OrderCancelReason[];
}[] = [
    {
        label: 'The client declined',
        reasons: ['client_changed_mind', 'price_too_high', 'found_cheaper'],
    },
    {
        label: "Couldn't reach or deliver",
        reasons: ['client_unreachable', 'invalid_address', 'invalid_phone'],
    },
    {
        label: 'Order problem',
        reasons: ['duplicate_order', 'out_of_stock', 'agent_error'],
    },
    {
        label: 'Flagged',
        reasons: ['blacklisted_client', 'fraud_suspected', 'other'],
    },
];

/**
 * Statuses the system owns, never offered in a picker. Mirrors
 * OrderConfirmationStatus::manuallySelectable() on the server, which
 * rejects them at validation — this list only keeps the UI from offering
 * what the request would refuse.
 *
 * "Assigned" is set when an order is auto-assigned or an admin
 * assigns/unassigns an agent.
 *
 * "Submitted to courier" is set only once the courier's API confirms the
 * parcel was created. Picking it by hand would claim a parcel exists at a
 * courier that never received one — an order that can never be tracked or
 * settled, and delivery stats counting a shipment that isn't real.
 *
 * "Test completed" is the terminal state a test order lands on
 * automatically when confirmed (UC-25).
 */
const SYSTEM_OWNED_CONFIRMATION_STATUSES: OrderConfirmationStatus[] = [
    'assigned',
    'submitted_to_courier',
    'test_completed',
];

export const MANUALLY_SELECTABLE_CONFIRMATION_STATUSES = Object.entries(
    confirmationStatusLabels,
).filter(
    ([value]) =>
        !SYSTEM_OWNED_CONFIRMATION_STATUSES.includes(
            value as OrderConfirmationStatus,
        ),
) as [OrderConfirmationStatus, string][];

/**
 * Groups the status picker by what the agent is actually deciding, so the
 * list reads as a handful of related choices instead of one flat run of 11
 * — "did I reach them" vs. "what did they decide" are different questions
 * asked at different points in a call.
 */
export const CONFIRMATION_STATUS_GROUPS: {
    label: string;
    statuses: OrderConfirmationStatus[];
}[] = [
    {
        label: 'Call outcome',
        statuses: ['no_answer', 'busy', 'voicemail', 'whatsapp_sent'],
    },
    {
        label: 'Client response',
        statuses: ['confirmed', 'confirmed_followup', 'callback', 'fake'],
    },
    {
        label: 'Resolution',
        statuses: ['cancelled'],
    },
];

/** The 3 transitions covering the large majority of calls — surfaced as one-tap buttons above the full picker. */
export const QUICK_CONFIRMATION_STATUSES: OrderConfirmationStatus[] = [
    'confirmed',
    'no_answer',
    'callback',
];

/**
 * Status events store the raw enum value as a loose string, and it may come
 * from either track (a confirmation transition or a delivery one). Render it
 * through here so history reads in the same words as the badges above it —
 * an agent seeing `no_answer → confirmed_followup` is reading the database,
 * not the product. Falls back to the raw value rather than hiding an event.
 */
export function statusEventLabel(value: string): string {
    return (
        confirmationStatusLabels[value as OrderConfirmationStatus] ??
        deliveryStatusLabels[value as OrderDeliveryStatus] ??
        value
    );
}

/** Mirrors App\Enums\OrderReturnReason. */
export const returnReasonLabels: Record<OrderReturnReason, string> = {
    client_refused: 'Client refused to accept the parcel',
    client_unreachable_at_delivery:
        'Unreachable at delivery (retries exhausted)',
    wrong_address: "Courier couldn't locate the address",
    payment_issue: "Client couldn't pay the COD amount",
    damaged_in_transit: 'Product arrived damaged, client refused',
    other: 'Other',
};

/**
 * Human label for `source_platform`, which carries two different kinds of
 * value: a manual order records how it reached the business (see
 * App\Enums\OrderSource), while a synced order records its store's platform
 * name (App\Enums\EcomPlatform — already display-cased, e.g. "Shopify").
 *
 * Only the manual values need mapping; anything else is a platform name and
 * is passed through, so a platform added later needs no change here. Empty
 * falls back to "Manual", matching the default OrderService writes when a
 * manual order is created without a stated source.
 */
export function orderSourceLabel(value: string | null | undefined): string {
    if (!value) {
        return 'Manual';
    }

    const manual: Record<string, string> = {
        whatsapp: 'WhatsApp',
        phone_call: 'Phone call',
        other: 'Other',
        manual: 'Manual',
    };

    return manual[value] ?? value;
}

/**
 * True when the source is one an agent typed in rather than a platform the
 * order synced from — the two read differently and are styled apart.
 */
export function isManualOrderSource(value: string | null | undefined): boolean {
    return (
        !value || ['whatsapp', 'phone_call', 'other', 'manual'].includes(value)
    );
}
