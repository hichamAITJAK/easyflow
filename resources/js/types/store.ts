export type StoreConnectionStatus = 'pending' | 'connected' | 'failed';

export type EcommercePlatform = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    logo_url: string | null;
};

export type Store = {
    id: number;
    business_id: number;
    platform_id: number;
    platform?: EcommercePlatform;
    name: string;
    slug: string | null;
    domain: string | null;
    logo_url: string | null;
    description: string | null;
    meta: Record<string, unknown> | null;
    external_store_id: string | null;
    connection_status: StoreConnectionStatus;
    last_synced_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * The stores list only ever needs this slice — the index endpoint selects
 * matching columns rather than shipping every `Store` field (encrypted
 * credentials aside, that's still meta/timestamps/slug/ids the list never
 * renders).
 */
export type StoreSummary = Pick<
    Store,
    | 'id'
    | 'name'
    | 'logo_url'
    | 'description'
    | 'domain'
    | 'connection_status'
    | 'last_synced_at'
> & {
    platform?: Pick<EcommercePlatform, 'name' | 'slug'>;
};
