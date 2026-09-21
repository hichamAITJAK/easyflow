import type { OrderDeliveryStatus } from './order';

/** Mirrors FulfillmentController::orderPayload(). */
export type FulfillmentOrderItem = {
    name: string | null;
    sku: string | null;
    quantity: number;
    thumbnail: string | null;
};

export type FulfillmentOrder = {
    id: number;
    reference: string | null;
    store: string | null;
    courier: string | null;
    customer_name: string | null;
    customer_address: string | null;
    customer_city: string | null;
    courier_tracking_number: string | null;
    delivery_status: OrderDeliveryStatus | null;
    items: FulfillmentOrderItem[];
    parcel_products: string | null;
    parcel_note: string | null;
    parcel_nature: string | null;
    parcel_open: boolean | null;
    parcel_fragile: boolean | null;
    parcel_replace: boolean | null;
    ready_for_pickup_at: string | null;
    return_received_at: string | null;
};

/**
 * The action a scan may perform, re-derived server-side from the parcel's
 * current status. Null means the parcel previews with no button — see the
 * FulfillmentController class docblock.
 */
export type FulfillmentAction = Extract<
    OrderDeliveryStatus,
    'ready_for_pickup' | 'return_received'
> | null;

export type FulfillmentScanResult = {
    order: FulfillmentOrder;
    action: FulfillmentAction;
};

export type FulfillmentSummary = {
    ready_to_prepare: number;
    returns_pending: number;
};

export type FulfillmentActivityEvent = {
    id: number;
    order_id: number;
    tracking_number: string | null;
    customer_name: string | null;
    from_status: string | null;
    to_status: string;
    created_at: string;
    can_undo: boolean;
};
