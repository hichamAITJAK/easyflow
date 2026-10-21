export type CourierSettlementStatus = 'pending' | 'reconciled' | 'disputed';

export type CourierSettlement = {
    id: number;
    business_id: number;
    delivery_account_id: number;
    delivery_account?: {
        id: number;
        label: string;
        courier?: { id: number; name: string; slug: string } | null;
    } | null;
    period_start: string;
    period_end: string;
    expected_amount: string;
    actual_amount: string | null;
    difference_amount: string | null;
    status: CourierSettlementStatus;
    reconciled_at: string | null;
    reconciled_by: number | null;
    reconciler?: { id: number; name: string } | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
};

export type DeliveryCashSettlementStatus = 'reconciled' | 'disputed';

/** One hand-over of COD cash from a delivery guy. */
export type DeliveryCashSettlement = {
    id: number;
    user_id: number;
    delivery_guy?: { id: number; name: string } | null;
    settled_by?: { id: number; name: string } | null;
    /** What the parcels added up to. */
    amount: string;
    received_amount: string | null;
    difference_amount: string;
    parcels_count: number;
    status: DeliveryCashSettlementStatus;
    note: string | null;
    created_at: string;
};

/** A delivery guy and the cash he is holding right now. */
export type DeliveryGuyCash = {
    id: number;
    name: string;
    owed: number;
    parcels: number;
};
