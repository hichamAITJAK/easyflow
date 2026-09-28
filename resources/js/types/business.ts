export type BusinessStatus = 'active' | 'suspended' | 'cancelled';

export type Business = {
    id: number;
    name: string;
    slug: string;
    status: BusinessStatus;
    users_count?: number;
    // Only loaded by the super-admin detail page's loadCount.
    stores_count?: number;
    products_count?: number;
    orders_count?: number;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type BusinessFilters = {
    search?: string;
    status?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
};
