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
