import type { ColumnDef } from '@tanstack/react-table';
import { Copy, History, MoreHorizontal } from 'lucide-react';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { deliveryStatusColors, deliveryStatusLabels } from '@/lib/order-status';
import type { Order, ParcelFilters } from '@/types';

function copyTrackingNumber(trackingNumber: string) {
    void navigator.clipboard.writeText(trackingNumber);
}

export function createColumns({
    t,
    filters,
    routeUrl,
    onViewHistory,
}: {
    t: Translator;
    filters: ParcelFilters;
    routeUrl: string;
    onViewHistory: (order: Order) => void;
}): ColumnDef<Order>[] {
    const sortHeader = (title: string, sortKey: string) => (
        <DataTableColumnHeaderServer
            title={title}
            sortKey={sortKey}
            currentSort={filters.sort}
            currentDirection={filters.direction}
            routeUrl={routeUrl}
            query={filters}
        />
    );

    return [
        {
            accessorKey: 'reference',
            header: t('Reference'),
            cell: ({ row }) => (
                <span className="font-medium">
                    {row.original.reference ?? '—'}
                </span>
            ),
        },
        {
            accessorKey: 'courier_tracking_number',
            header: t('Tracking number'),
            cell: ({ row }) => {
                const trackingNumber = row.original.courier_tracking_number;

                if (!trackingNumber) {
                    return '—';
                }

                return (
                    <div className="flex items-center gap-1">
                        <span className="font-mono text-sm">
                            {trackingNumber}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-6"
                            onClick={() =>
                                copyTrackingNumber(trackingNumber)
                            }
                        >
                            <Copy className="size-3.5" />
                        </Button>
                    </div>
                );
            },
        },
        {
            id: 'courier',
            // Still keyed on the courier name so sorting/filtering by this
            // column keeps grouping parcels by carrier rather than by whatever
            // a business happened to name its account.
            accessorFn: (order) => order.delivery_account?.courier?.name ?? '—',
            header: t('Courier'),
            cell: ({ row }) => {
                const account = row.original.delivery_account;

                if (!account) {
                    return <span className="text-muted-foreground">—</span>;
                }

                // A business can hold several accounts with the same courier
                // (different cities, different contracts), so the carrier name
                // alone doesn't say which one shipped the parcel — that is the
                // account label. Carrier leads because it is what identifies
                // the parcel; the label qualifies it underneath.
                return (
                    <div className="flex flex-col">
                        <span className="font-medium">
                            {account.courier?.name ?? '—'}
                        </span>
                        {account.label && (
                            <span className="truncate text-sm text-muted-foreground">
                                {account.label}
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'customer',
            header: t('Customer'),
            cell: ({ row }) => {
                const order = row.original;

                return (
                    <div className="flex flex-col">
                        <span className="font-medium">
                            {order.customer_name ?? '—'}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {order.customer_phone ?? '—'}
                        </span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'customer_city',
            header: t('City'),
            cell: ({ row }) => row.original.customer_city ?? '—',
        },
        {
            accessorKey: 'delivery_status',
            header: () => sortHeader(t('Delivery status'), 'delivery_status'),
            cell: ({ row }) => {
                const status = row.original.delivery_status;

                return status ? (
                    <Badge
                        variant="outline"
                        className={deliveryStatusColors[status]}
                    >
                        {t(deliveryStatusLabels[status])}
                    </Badge>
                ) : (
                    '—'
                );
            },
        },
        {
            accessorKey: 'delivery_cost',
            header: () => sortHeader(t('Delivery cost'), 'delivery_cost'),
            cell: ({ row }) => row.original.delivery_cost ?? '—',
        },
        {
            accessorKey: 'total_amount',
            header: () => sortHeader(t('Total'), 'total_amount'),
            cell: ({ row }) => row.original.total_amount,
        },
        {
            accessorKey: 'shipped_at',
            header: () => sortHeader(t('Shipped'), 'shipped_at'),
            cell: ({ row }) =>
                row.original.shipped_at
                    ? formatDateTime(row.original.shipped_at)
                    : '—',
        },
        {
            accessorKey: 'ready_for_pickup_at',
            header: t('Scanned out'),
            // The two scan columns are the warehouse's own record of a parcel
            // physically leaving and physically coming back — the outcomes
            // that matter most on this table. A filled chip lets a scan be
            // picked out of a dense grid of timestamps without reading any of
            // them: green for the good exit, red for the parcel that came
            // back.
            //
            // Values are `badgeColors.success` / `.danger` from lib/order-status
            // spelled out, so these chips sit in the same palette as the
            // delivery-status badges already on this table. Colour is never
            // the sole carrier — the column header names the event, and an
            // unscanned parcel shows a muted dash.
            cell: ({ row }) =>
                row.original.ready_for_pickup_at ? (
                    <Badge
                        variant="outline"
                        className="border-emerald-200 bg-emerald-100 font-normal whitespace-nowrap text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-400"
                    >
                        {formatDateTime(row.original.ready_for_pickup_at)}
                    </Badge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            accessorKey: 'return_received_at',
            header: t('Scanned returned'),
            cell: ({ row }) =>
                row.original.return_received_at ? (
                    <Badge
                        variant="outline"
                        className="border-red-200 bg-red-100 font-normal whitespace-nowrap text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-400"
                    >
                        {formatDateTime(row.original.return_received_at)}
                    </Badge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'actions',
            enableSorting: false,
            enableHiding: false,
            // The two scan columns say when a parcel moved; history says
            // everything it did in between, including courier-driven
            // transitions that never touch a scan timestamp.
            cell: ({ row }) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={t('Parcel actions')}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onSelect={() => onViewHistory(row.original)}
                        >
                            <History />
                            {t('View history')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];
}
