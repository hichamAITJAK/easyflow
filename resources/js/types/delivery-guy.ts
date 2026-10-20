import type { OrderDeliveryStatus } from './order';

/** Mirrors DeliveryController::payload(). */
export type DeliveryParcel = {
    id: number;
    reference: string | null;
    courier_tracking_number: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    customer_address: string | null;
    customer_city: string | null;
    total_amount: string;
    delivery_status: OrderDeliveryStatus | null;
    parcel_note: string | null;
    parcel_open: boolean | null;
    parcel_fragile: boolean | null;
    parcel_replace: boolean | null;
    store: string | null;
    items: {
        name: string | null;
        sku: string | null;
        quantity: number;
        thumbnail: string | null;
    }[];
};

/**
 * What the guy may mark, decided server-side from the parcel's status,
 * with the courier's own label (Livré, Pas de réponse…) and the status it
 * maps to.
 */
export type DeliveryAction = {
    key: string;
    label: string;
    status: OrderDeliveryStatus;
    needs_date: boolean;
};

export type DeliveryScanResult = {
    order: DeliveryParcel;
    actions: DeliveryAction[];
};

export type DeliverySummary = {
    to_collect: number;
    on_road: number;
    delivered_today: number;
    cash_owed: number;
    city: string | null;
};

export type DeliveryActivityEvent = {
    id: number;
    order_id: number;
    reference: string | null;
    tracking_number: string | null;
    customer_name: string | null;
    customer_city: string | null;
    amount: string | null;
    from_status: string | null;
    to_status: string;
    note: string | null;
    created_at: string;
    can_undo: boolean;
};

export type DeliveryCash = {
    owed: number;
    parcels: {
        id: number;
        reference: string | null;
        tracking_number: string | null;
        customer_name: string | null;
        customer_city: string | null;
        amount: string;
        delivered_at: string;
    }[];
    last_settlement: {
        amount: string;
        parcels_count: number;
        created_at: string;
    } | null;
};
