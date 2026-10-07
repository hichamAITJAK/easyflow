export type Product = {
    id: number;
    business_id: number;
    /** Null means a manually-added product, not synced from any store. */
    store_id: number | null;
    store?: { id: number; name: string } | null;
    external_product_id: string | null;
    name: string;
    sku: string | null;
    description: string | null;
    price: string | null;
    inventory_quantity: number | null;
    /** Sum of this product's variants' inventory_quantity, when it has any. */
    variants_sum_inventory_quantity: number | null;
    /** True once stock was edited in EasyFlow; the platform sync then leaves it alone. */
    stock_managed_locally: boolean;
    public_url: string | null;
    /** The first gallery image (position 0); kept in sync by ProductImageWriter. */
    thumbnail: string | null;
    tags: string[] | null;
    status: boolean | null;
    is_active: boolean;
    is_test: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * As sent to the product edit page: `path` is the raw storage path
 * (resubmitted on save), `url` is the resolved, displayable image URL.
 */
export type ProductImage = {
    id: number;
    path: string;
    url: string;
};

export type ProductVariantOption = {
    name: string;
    value: string;
};

export type ProductVariant = {
    id: number;
    sku: string | null;
    image: string | null;
    price: string | null;
    inventory_quantity: number | null;
    is_available: boolean;
    options: ProductVariantOption[];
};

export type ProductMetrics = {
    total: number;
    active: number;
    inactive: number;
    test: number;
};

export type ProductFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
    /** Comma-separated store ids — query-string friendly. */
    store_ids?: string;
    price_min?: string;
    price_max?: string;
};
