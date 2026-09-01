import type { ColumnDef } from '@tanstack/react-table';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type {
    SubscriptionHistoryFilters,
    SubscriptionRequest,
    SubscriptionStatus,
} from '@/types';

const STATUS_STYLES: Record<SubscriptionStatus, string> = {
    trialing: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    pending:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    rejected: 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400',
    expired: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground',
};

const STATUS_LABELS: Record<SubscriptionStatus, string> = {
    trialing: 'Trial',
    pending: 'Pending',
    active: 'Active',
    rejected: 'Rejected',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

export function createHistoryColumns({
    filters,
    routeUrl,
}: {
    filters: SubscriptionHistoryFilters;
    routeUrl: string;
}): ColumnDef<SubscriptionRequest>[] {
    const sortHeader = (title: string, sortKey: string) => (
        <DataTableColumnHeaderServer
            title={title}
            sortKey={sortKey}
            currentSort={filters.history_sort}
            currentDirection={filters.history_direction}
            routeUrl={routeUrl}
            query={filters}
            sortParam="history_sort"
            directionParam="history_direction"
            pageParam="history_page"
        />
    );

    return [
        {
            accessorKey: 'businessName',
            id: 'business',
            header: () => sortHeader('Business', 'business_name'),
            cell: ({ row }) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">
                        {row.original.businessName}
                    </span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.referenceCode}
                    </span>
                </div>
            ),
        },
        {
            accessorKey: 'planName',
            id: 'plan',
            header: () => sortHeader('Plan', 'plan_name'),
            cell: ({ row }) => (
                <div className="grid gap-0.5">
                    <span>{row.original.planName}</span>
                    {row.original.planPrice && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {Number(row.original.planPrice).toLocaleString()}{' '}
                            {row.original.planCurrency}
                        </span>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'status',
            id: 'status',
            header: () => sortHeader('Status', 'status'),
            cell: ({ row }) => (
                <div className="grid gap-1">
                    <Badge
                        variant="outline"
                        className={cn(
                            'w-fit text-xs font-medium',
                            STATUS_STYLES[row.original.status],
                        )}
                    >
                        {STATUS_LABELS[row.original.status]}
                    </Badge>
                    {row.original.status === 'rejected' &&
                        row.original.rejectionReason && (
                            <span className="max-w-52 truncate text-xs text-muted-foreground">
                                {row.original.rejectionReason}
                            </span>
                        )}
                </div>
            ),
        },
        {
            accessorKey: 'startsAt',
            id: 'period',
            header: () => sortHeader('Period', 'starts_at'),
            cell: ({ row }) =>
                row.original.startsAt && row.original.endsAt ? (
                    <span className="text-muted-foreground tabular-nums">
                        {row.original.startsAt} – {row.original.endsAt}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            accessorKey: 'activatedBy',
            id: 'handled by',
            header: 'Handled by',
            cell: ({ row }) => (
                <span className="text-muted-foreground">
                    {row.original.activatedBy ?? '—'}
                </span>
            ),
        },
    ];
}
