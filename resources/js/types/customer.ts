export type Customer = {
    id: number;
    business_id: number;
    name: string;
    phone: string;
    address: string | null;
    city: string | null;
    orders_count: number;
    delivered_orders_count: number;
    returned_orders_count: number;
    last_order_at: string | null;
    is_best_customer: boolean;
    is_blacklisted: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type CustomerMetrics = {
    total: number;
    best: number;
    blacklisted: number;
};

export type CustomerFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
    best?: string;
    blacklisted?: string;
};

export type CustomerBlacklistEntry = {
    id: number;
    business_id: number;
    phone_hash: string;
    phone_encrypted: string;
    reason: string | null;
    notes: string | null;
    added_by_user_id: number | null;
    added_by_user?: { id: number; name: string } | null;
    created_at: string;
    [key: string]: unknown;
};

export type CustomerBlacklistFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
};
