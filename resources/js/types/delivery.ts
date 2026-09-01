export type DeliveryAccountStatus = 'active' | 'unverified';

export type DeliveryCourrier = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    logo: string | null;
};

export type DeliveryCourrierCity = {
    id: number;
    courrier_id: number;
    external_courrier_id: string | null;
    name: string;
    arabic_name: string | null;
};

export type CitiesByCourier = Record<string, DeliveryCourrierCity[]>;

export type DeliveryAccount = {
    id: number;
    business_id: number;
    courier_id: number;
    collect_city_id: number | null;
    label: string;
    is_default: boolean;
    status: DeliveryAccountStatus;
    courier?: DeliveryCourrier;
    collect_city?: DeliveryCourrierCity | null;
    created_at: string;
    updated_at: string;
};
