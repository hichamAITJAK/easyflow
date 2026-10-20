import { Head, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Printer, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { PILL_TABS_COUNT } from '@/components/pill-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/hooks/use-translation';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import {
    local as fulfillmentLocal,
    tickets as fulfillmentTickets,
} from '@/routes/fulfillment';
import type { Order } from '@/types';

type Filter = 'to_prepare' | 'ready' | 'all';

type Parcel = Pick<
    Order,
    | 'id'
    | 'reference'
    | 'courier_tracking_number'
    | 'customer_name'
    | 'customer_phone'
    | 'customer_address'
    | 'customer_city'
    | 'total_amount'
    | 'delivery_status'
    | 'delivery_guy'
    | 'shipped_at'
> & { store?: { id: number; name: string } | null };

/**
 * Own delivery, warehouse side: the parcels going out with the business's
 * delivery guys. Tick the ones to prepare, print their labels, then scan
 * each parcel out on the Fulfilment tab and hand the lot to the guy.
 */
export default function FulfillmentLocal({
    filter,
    parcels,
    counts,
}: {
    filter: Filter;
    parcels: Parcel[];
    counts: { to_prepare: number; ready: number };
}) {
    const { t } = useTranslation();
    const [rowSelection, setRowSelection] = useState({});

    const filters: { key: Filter; label: string; count?: number }[] = [
        { key: 'to_prepare', label: t('To prepare'), count: counts.to_prepare },
        { key: 'ready', label: t('Ready'), count: counts.ready },
        { key: 'all', label: t('All') },
    ];

    const columns = useMemo<ColumnDef<Parcel>[]>(
        () => [
            {
                id: 'select',
                header: ({ table }) => (
                    <Checkbox
                        checked={
                            table.getIsAllPageRowsSelected() ||
                            (table.getIsSomePageRowsSelected() &&
                                'indeterminate')
                        }
                        onCheckedChange={(value) =>
                            table.toggleAllPageRowsSelected(!!value)
                        }
                        aria-label={t('Select all')}
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={row.getIsSelected()}
                        onCheckedChange={(value) => row.toggleSelected(!!value)}
                        aria-label={t('Select row')}
                    />
                ),
                enableSorting: false,
                enableHiding: false,
            },
            {
                accessorKey: 'courier_tracking_number',
                header: t('Tracking'),
                cell: ({ row }) => (
                    <div className="grid gap-0.5">
                        <span className="font-mono text-sm">
                            {row.original.courier_tracking_number}
                        </span>
                        <span className="font-mono text-xs text-muted-foreground">
                            {row.original.reference}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'customer_name',
                header: t('Customer'),
                cell: ({ row }) => (
                    <div className="grid max-w-[260px] gap-0.5">
                        <span className="truncate font-medium">
                            {row.original.customer_name}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {row.original.customer_address}
                        </span>
                        <span className="font-mono text-xs text-muted-foreground">
                            {row.original.customer_phone}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'customer_city',
                header: t('City'),
                cell: ({ row }) => row.original.customer_city ?? '—',
            },
            {
                id: 'delivery_guy',
                header: t('Delivery guy'),
                cell: ({ row }) => {
                    const guy = row.original.delivery_guy;

                    return guy ? (
                        <div className="grid gap-0.5">
                            <span className="font-medium">{guy.name}</span>
                            {guy.delivery_city && (
                                <span className="text-xs text-muted-foreground">
                                    {guy.delivery_city}
                                </span>
                            )}
                        </div>
                    ) : (
                        '—'
                    );
                },
            },
            {
                id: 'store',
                header: t('Store'),
                cell: ({ row }) => row.original.store?.name ?? '—',
            },
            {
                accessorKey: 'total_amount',
                header: () => <div className="text-right">{t('COD')}</div>,
                cell: ({ row }) => (
                    <div className="text-right font-mono font-semibold tabular-nums">
                        {row.original.total_amount} MAD
                    </div>
                ),
            },
            {
                accessorKey: 'delivery_status',
                header: t('Status'),
                cell: ({ row }) =>
                    row.original.delivery_status ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                statusChipClass,
                                deliveryStatusColors[
                                    row.original.delivery_status
                                ],
                            )}
                        >
                            {t(
                                deliveryStatusLabels[
                                    row.original.delivery_status
                                ],
                            )}
                        </Badge>
                    ) : (
                        '—'
                    ),
            },
        ],
        [t],
    );

    const table = useReactTable({
        data: parcels,
        columns,
        getRowId: (parcel) => String(parcel.id),
        enableRowSelection: true,
        onRowSelectionChange: setRowSelection,
        getCoreRowModel: getCoreRowModel(),
        state: { rowSelection },
    });

    const selectedIds = table
        .getSelectedRowModel()
        .rows.map((row) => row.original.id);

    const printSelected = () => {
        window.open(
            fulfillmentTickets({ query: { ids: selectedIds.join(',') } }).url,
            '_blank',
        );
    };

    return (
        <>
            <Head title={t('Local delivery')} />

            <div className="mx-auto w-full max-w-6xl space-y-4 px-4 pt-6 pb-4 lg:pb-10">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('Local delivery')}
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {t(
                            'Parcels going out with your own delivery guys. Print the labels, scan each parcel out, hand them over.',
                        )}
                    </p>
                </div>

                <DataTableCard>
                    <DataTableCardToolbar className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex gap-1 rounded-lg border border-border bg-muted/40 p-1">
                            {filters.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() =>
                                        router.get(
                                            fulfillmentLocal({
                                                query: { status: item.key },
                                            }).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className={cn(
                                        'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium',
                                        filter === item.key
                                            ? 'bg-card text-foreground shadow-xs'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {item.label}
                                    {item.count !== undefined && (
                                        <span className={PILL_TABS_COUNT}>
                                            {item.count}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>

                        <Button
                            size="sm"
                            disabled={selectedIds.length === 0}
                            onClick={printSelected}
                        >
                            <Printer />
                            {t('Print tickets')}
                            {selectedIds.length > 0 &&
                                ` (${selectedIds.length})`}
                        </Button>
                    </DataTableCardToolbar>

                    <DataTableCardTable>
                        <DataTable
                            table={table}
                            columnCount={columns.length}
                            emptyIcon={<Truck />}
                            emptyMessage={t('No local parcels')}
                            emptyDescription={t(
                                'Parcels the confirmation team hands to a delivery guy will show up here.',
                            )}
                        />
                    </DataTableCardTable>
                </DataTableCard>
            </div>
        </>
    );
}
