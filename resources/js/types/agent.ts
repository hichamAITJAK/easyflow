export type CommissionPaymentMode =
    'salary' | 'commission' | 'salary_and_commission';
export type SalaryPeriod = 'weekly' | 'monthly';
export type CommissionAmountType = 'fixed' | 'percentage';
export type PerformanceMetric = 'confirmation_rate' | 'delivery_success_rate';
export type PerformanceTargetPeriod = 'daily' | 'weekly' | 'monthly';
export type InvoiceStatus = 'draft' | 'issued' | 'paid' | 'cancelled';

export type AgentScope = {
    id: number;
    business_id: number;
    user_id: number;
    store_id: number | null;
    store?: { id: number; name: string } | null;
    product_id: number | null;
    product?: { id: number; name: string } | null;
};

/**
 * A single row in an agent's commission setup. store_id and product_id both
 * null means this is the agent's default rule; either one set means this
 * row is a pricing override that takes precedence over the default for
 * that specific store or product.
 */
export type CommissionRule = {
    id: number;
    business_id: number;
    user_id: number | null;
    payment_mode: CommissionPaymentMode;
    store_id: number | null;
    store?: { id: number; name: string } | null;
    product_id: number | null;
    product?: { id: number; name: string } | null;
    trigger_status: string | null;
    amount_type: CommissionAmountType | null;
    amount: string | null;
    salary_amount: string | null;
    salary_period: SalaryPeriod | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type PerformanceTarget = {
    id: number;
    business_id: number;
    user_id: number | null;
    metric: PerformanceMetric;
    target_percentage: string;
    /** Payout for meeting this target over its period; null = no bonus. */
    bonus_amount: string | null;
    period: PerformanceTargetPeriod;
    min_orders_for_evaluation: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type Invoice = {
    id: number;
    business_id: number;
    /** An invoice always covers exactly one agent — never a mix. */
    user_id: number | null;
    user?: { id: number; name: string; avatar: string | null } | null;
    invoice_number: string;
    period_start: string;
    period_end: string;
    total_amount: string;
    status: InvoiceStatus;
    notes: string | null;
    created_at: string;
    updated_at: string;
};

export type CommissionLedgerEntry = {
    id: number;
    business_id: number;
    user_id: number;
    user?: { id: number; name: string; avatar: string | null } | null;
    /** Null on a bonus entry, which is earned over a period, not an order. */
    order_id: number | null;
    order?: { id: number; reference: string | null } | null;
    /** Names the metric and period a bonus covers; null on order entries. */
    description?: string | null;
    commission_rule_id: number | null;
    invoice_id: number | null;
    invoice?: Pick<Invoice, 'id' | 'invoice_number' | 'status'> | null;
    amount: string;
    entry_type: 'earned' | 'reversal';
    reversed_entry_id: number | null;
    created_at: string;
};
