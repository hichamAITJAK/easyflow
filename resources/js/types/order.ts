export type OrderConfirmationStatus =
    | 'new'
    | 'assigned'
    | 'confirmed'
    | 'confirmed_followup'
    | 'callback'
    | 'fake'
    | 'voicemail'
    | 'no_answer'
    | 'busy'
    | 'whatsapp_sent'
    | 'cancelled'
    | 'submitted_to_courier'
    | 'test_completed';

export type OrderDeliveryStatus =
    | 'awaiting_pickup'
    | 'ready_for_pickup'
    | 'in_transit'
    | 'out_for_delivery'
    | 'postponed'
    | 'changed'
    | 'delivery_attempt_failed'
    | 'refused'
    | 'delivered'
    | 'returned_in_transit'
    | 'return_received'
    | 'cancelled_at_courier';

/**
 * Valid source_platform values for a manually-created order (how it
 * reached the business) — platform-synced orders store their store's
 * platform slug in the same column instead.
 */
export type OrderSource = 'whatsapp' | 'phone_call' | 'other';

export type OrderCancelReason =
    | 'client_unreachable'
    | 'client_changed_mind'
    | 'price_too_high'
    | 'found_cheaper'
    | 'duplicate_order'
    | 'blacklisted_client'
    | 'invalid_address'
    | 'invalid_phone'
    | 'out_of_stock'
    | 'fraud_suspected'
    | 'agent_error'
    | 'other';

export type OrderReturnReason =
    | 'client_refused'
    | 'client_unreachable_at_delivery'
    | 'wrong_address'
    | 'payment_issue'
    | 'damaged_in_transit'
    | 'other';

export type OrderStatusEvent = {
    id: number;
    order_id: number;
    from_status: string | null;
    to_status: string;
    note: string | null;
    created_at: string | null;
    changed_by_user?: { id: number; name: string } | null;
};

export type OrderItem = {
    id: number;
    order_id: number;
    product_id: number | null;
    product_variant_id: number | null;
    product_name_snapshot: string;
    sku_snapshot: string | null;
    quantity: number;
    unit_price: string;
    product?: {
        id: number;
        name: string;
        thumbnail: string | null;
        public_url: string | null;
    } | null;
};

export type Order = {
    id: number;
    reference: string | null;
    business_id: number;
    store_id: number | null;
    store?: {
        id: number;
        name: string;
        platform?: { id: number; name: string } | null;
    } | null;
    source_platform: string;
    assigned_agent_id: number | null;
    assigned_agent?: {
        id: number;
        name: string;
        avatar?: string | null;
    } | null;
    customer_name?: string;
    customer_phone?: string;
    customer_address?: string;
    customer_city: string | null;
    items?: OrderItem[];
    total_amount: string;
    delivery_cost: string | null;
    returned_cost: string | null;
    refused_cost: string | null;
    confirmation_status: OrderConfirmationStatus;
    delivery_status: OrderDeliveryStatus | null;
    cancellation_reason_code: OrderCancelReason | null;
    return_reason_code: OrderReturnReason | null;
    notes: string | null;
    status_events?: OrderStatusEvent[];
    is_duplicate_flagged: boolean;
    is_blacklist_flagged: boolean;
    is_test: boolean;
    courier_tracking_number: string | null;
    courier_slug: string | null;
    delivery_driver_name: string | null;
    delivery_driver_phone: string | null;
    shipped_at: string | null;
    delivery_account_id: number | null;
    delivery_account?: {
        id: number;
        label: string;
        courier?: { id: number; name: string; slug: string } | null;
    } | null;
    parcel_note: string | null;
    parcel_nature: string | null;
    parcel_open: boolean | null;
    parcel_fragile: boolean | null;
    parcel_replace: boolean | null;
    parcel_products: { ref: string; qnty: number }[] | null;
    ready_for_pickup_at: string | null;
    return_received_at: string | null;
    ordered_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type OrderMetrics = {
    new: number;
    confirmed: number;
    submitted_to_courier: number;
    delivered: number;
    cancelled: number;
};

export type OrderFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
    confirmation_status?: string;
    delivery_status?: string;
    /** Comma-separated store ids — query-string friendly. */
    store_ids?: string;
    assigned_agent_id?: string;
    date_from?: string;
    date_to?: string;
    bucket?: string;
};

export type OrderQueueBucket =
    | 'all'
    | 'new'
    | 'follow_up'
    | 'confirmed'
    | 'shipped';

export type OrderBucketCounts = Record<OrderQueueBucket, number>;

export type ParcelFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
    delivery_status?: string;
    /** Comma-separated delivery account ids — query-string friendly. */
    delivery_account_ids?: string;
    date_from?: string;
    date_to?: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};
