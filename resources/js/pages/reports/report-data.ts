export type ReportColumn = {
    key: string;
    label: string;
    align?: 'left' | 'right';
};

export type ReportChart =
    | { type: 'bar'; dataKey: string; nameKey: string; label: string }
    | { type: 'line'; dataKeys: { key: string; label: string; color: string }[]; nameKey: string }
    | { type: 'area'; dataKeys: { key: string; label: string; color: string }[]; nameKey: string }
    | null;

export type ReportDefinition = {
    id: string;
    title: string;
    description: string;
    columns: ReportColumn[];
    chart: ReportChart;
};

export const reportDefinitions: ReportDefinition[] = [
    {
        id: 'revenue',
        title: 'Revenue',
        description: 'Revenue out, expected, arrived, and waiting over the selected period.',
        chart: {
            type: 'line',
            nameKey: 'date',
            dataKeys: [
                { key: 'arrived', label: 'Arrived', color: 'var(--chart-1)' },
                { key: 'expected', label: 'Expected', color: 'var(--chart-2)' },
                { key: 'waiting', label: 'Waiting', color: 'var(--chart-3)' },
            ],
        },
        columns: [
            { key: 'order', label: 'Order' },
            { key: 'store', label: 'Store' },
            { key: 'status', label: 'Status' },
            { key: 'amount', label: 'Amount (MAD)', align: 'right' },
            { key: 'date', label: 'Date' },
        ],
    },
    {
        id: 'settlements',
        title: 'Settlement reconciliation',
        description: 'Expected vs settled amounts by courier, with disputes flagged.',
        chart: null,
        columns: [
            { key: 'courier', label: 'Courier' },
            { key: 'expected', label: 'Expected (MAD)', align: 'right' },
            { key: 'settled', label: 'Settled (MAD)', align: 'right' },
            { key: 'diff', label: 'Diff (MAD)', align: 'right' },
            { key: 'status', label: 'Status' },
        ],
    },
    {
        id: 'commissions',
        title: 'Commission',
        description: 'Commission earned per agent based on confirmed orders.',
        chart: { type: 'bar', dataKey: 'commission', nameKey: 'agent', label: 'Commission (MAD)' },
        columns: [
            { key: 'agent', label: 'Agent' },
            { key: 'confirmed', label: 'Confirmed orders', align: 'right' },
            { key: 'rate', label: 'Rate', align: 'right' },
            { key: 'amount', label: 'Commission (MAD)', align: 'right' },
            { key: 'invoice', label: 'Invoice' },
        ],
    },
    {
        id: 'cancellations',
        title: 'Cancellation & return',
        description: 'Cancelled and returned orders broken down by reason.',
        chart: { type: 'bar', dataKey: 'count', nameKey: 'reason', label: 'Orders' },
        columns: [
            { key: 'order', label: 'Order' },
            { key: 'store', label: 'Store' },
            { key: 'reason', label: 'Reason' },
            { key: 'stage', label: 'Stage' },
            { key: 'date', label: 'Date' },
        ],
    },
    {
        id: 'agent-performance',
        title: 'Agent performance',
        description: 'Confirmation, delivery, and return rates per agent against objective.',
        chart: { type: 'bar', dataKey: 'confirmedRate', nameKey: 'agent', label: 'Confirmation rate %' },
        columns: [
            { key: 'agent', label: 'Agent' },
            { key: 'leads', label: 'Leads', align: 'right' },
            { key: 'confirmedRate', label: 'Confirmed', align: 'right' },
            { key: 'deliveredRate', label: 'Delivered', align: 'right' },
            { key: 'returnRate', label: 'Client returned', align: 'right' },
            { key: 'objective', label: 'Objective', align: 'right' },
            { key: 'score', label: 'Score', align: 'right' },
        ],
    },
    {
        id: 'courier-performance',
        title: 'Courier performance',
        description: 'Delivery rate, shipped, delivered, returned, scanned per courier.',
        chart: { type: 'bar', dataKey: 'deliveredRate', nameKey: 'courier', label: 'Delivery rate %' },
        columns: [
            { key: 'courier', label: 'Courier' },
            { key: 'shipped', label: 'Shipped', align: 'right' },
            { key: 'delivered', label: 'Delivered', align: 'right' },
            { key: 'returned', label: 'Returned', align: 'right' },
            { key: 'scanned', label: 'Scanned', align: 'right' },
            { key: 'avgTime', label: 'Avg delivery time', align: 'right' },
        ],
    },
    {
        id: 'store-sales',
        title: 'Product / store sales',
        description: 'Orders, confirmation, delivery, and revenue per store, with best products.',
        chart: { type: 'bar', dataKey: 'revenue', nameKey: 'store', label: 'Revenue (MAD)' },
        columns: [
            { key: 'store', label: 'Store' },
            { key: 'orders', label: 'Orders', align: 'right' },
            { key: 'confirmedRate', label: 'Confirmed', align: 'right' },
            { key: 'deliveredRate', label: 'Delivered', align: 'right' },
            { key: 'bestProduct', label: 'Best product' },
            { key: 'revenue', label: 'Revenue (MAD)', align: 'right' },
        ],
    },
    {
        id: 'stranded-orders',
        title: 'Stranded orders',
        description: 'Orders stuck in a stage longer than expected — needs action.',
        chart: null,
        columns: [
            { key: 'order', label: 'Order' },
            { key: 'store', label: 'Store' },
            { key: 'stage', label: 'Stuck stage' },
            { key: 'days', label: 'Days stuck', align: 'right' },
            { key: 'assignee', label: 'Assigned to' },
        ],
    },
    {
        id: 'geography-time',
        title: 'Geography & time',
        description: 'Delivery rate by city and order volume by time slot.',
        chart: {
            type: 'area',
            nameKey: 'hour',
            dataKeys: [
                { key: 'confirmedRate', label: 'Confirmation rate', color: 'var(--chart-1)' },
                { key: 'deliveredRate', label: 'Delivery rate', color: 'var(--success)' },
            ],
        },
        columns: [
            { key: 'city', label: 'City' },
            { key: 'orders', label: 'Orders', align: 'right' },
            { key: 'confirmedRate', label: 'Confirmed', align: 'right' },
            { key: 'deliveredRate', label: 'Delivered', align: 'right' },
            { key: 'peakTime', label: 'Peak time' },
        ],
    },
];
