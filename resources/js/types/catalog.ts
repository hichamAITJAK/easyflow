/** An e-commerce platform row as presented to the super admin. */
export type CatalogPlatform = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    logo_url: string | null;
    /** The repo-shipped icon for this slug, when one exists. */
    default_logo_url?: string | null;
    stores_count: number;
    created_at: string;
};

/** A delivery courier row as presented to the super admin. */
export type CatalogCourier = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    logo: string | null;
    cities_count: number;
    delivery_accounts_count: number;
    /** Whether SyncCourierCitiesCommand has an API wired up for it. */
    syncable: boolean;
    /** The repo-shipped icon for this slug, when one exists. */
    default_logo?: string | null;
};

export type CourierCity = {
    id: number;
    courrier_id: number;
    external_courrier_id: string | null;
    name: string;
    arabic_name: string | null;
    created_at: string;
    updated_at: string;
};

export type CourierCityFilters = {
    search?: string;
    sort?: string;
    direction?: string;
    per_page?: string;
    page?: string;
};
