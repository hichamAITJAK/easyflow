export type SubscriptionStatus =
    | 'trialing'
    | 'pending'
    | 'active'
    | 'rejected'
    | 'expired'
    | 'cancelled';

export type SubscriptionPaymentMethod = 'bank_transfer' | 'cash';

export type Plan = {
    id: number;
    name: string;
    slug: string;
    price: string;
    currency: string;
    duration_days: number;
    limits: Record<string, number> | null;
    is_active: boolean;
};

/** A subscription row as presented by SubscriptionController. */
export type SubscriptionSummary = {
    id: number;
    planName: string;
    isTrial: boolean;
    status: SubscriptionStatus;
    referenceCode: string;
    startsAt: string | null;
    endsAt: string | null;
    submittedAt: string | null;
    daysRemaining: number;
    totalDays: number | null;
    inGracePeriod: boolean;
    graceEndsAt: string | null;
    paidAmount: string | null;
    paymentMethod: SubscriptionPaymentMethod | null;
    paymentReference: string | null;
    rejectionReason: string | null;
};

/** Compact state shared on every Inertia page for tenant users. */
export type SharedSubscriptionState = {
    status: SubscriptionStatus;
    isTrial: boolean;
    endsAt: string | null;
    daysRemaining: number;
    inGracePeriod: boolean;
    graceEndsAt: string | null;
};

export type BankDetails = {
    account_holder: string;
    bank_name: string;
    rib: string;
};

/**
 * Query-string filters for the super admin subscription history table. The
 * `history_` prefix keeps them from colliding with the pending queue that
 * shares the same URL.
 */
export type SubscriptionHistoryFilters = {
    history_search?: string;
    history_status?: string;
    history_sort?: string;
    history_direction?: string;
    history_per_page?: string;
    history_page?: string;
};

/** A payment request row as presented to the super admin review queue. */
export type SubscriptionRequest = {
    id: number;
    businessName: string;
    planName: string;
    planPrice: string | null;
    planCurrency: string | null;
    status: SubscriptionStatus;
    referenceCode: string;
    paymentMethod: SubscriptionPaymentMethod | null;
    paymentReference: string | null;
    receiptUrl: string | null;
    submittedAt: string | null;
    startsAt: string | null;
    endsAt: string | null;
    activatedBy: string | null;
    rejectionReason: string | null;
    notes: string | null;
};
