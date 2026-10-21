import type { ColumnDef } from '@tanstack/react-table';
import {
    getCoreRowModel,
    getFilteredRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { Search, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { DeliveryGuyParcel } from '@/types/delivery-guy';

/**
 * Every parcel ever handed to the delivery guy, as a table he can search
 * by tracking number, customer or city.
 */
export function DeliveryParcelsTable({
    parcels,
    loading,
}: {
    parcels: DeliveryGuyParcel[];
    loading: boolean;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');

    const columns = useMemo<ColumnDef<DeliveryGuyParcel>[]>(
        () => [
            {
                accessorKey: 'tracking_number',
                header: t('Tracking'),
                cell: ({ row }) => (
                    <div className="grid gap-0.5">
                        <span className="font-mono text-sm">
                            {row.original.tracking_number ?? '—'}
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
                    <div className="grid max-w-[240px] gap-0.5">
                        <span className="truncate font-medium">
                            {row.original.customer_name}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {[
                                row.original.customer_address,
                                row.original.customer_city,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </span>
                        <span className="font-mono text-xs text-muted-foreground">
                            {row.original.customer_phone}
                        </span>
                    </div>
                ),
            },
            {
                accessorKey: 'amount',
                header: () => <div className="text-right">{t('Amount')}</div>,
                cell: ({ row }) => (
                    <div className="text-right font-mono font-semibold tabular-nums">
                        {row.original.amount} MAD
                    </div>
                ),
            },
            {
                accessorKey: 'delivery_status',
                header: t('Status'),
                cell: ({ row }) =>
                    row.original.delivery_status ? (
                        <div className="flex flex-wrap items-center gap-1">
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
                            {row.original.settled && (
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        statusChipClass,
                                        'text-muted-foreground',
                                    )}
                                >
                                    {t('Cash handed over')}
                                </Badge>
                            )}
                        </div>
                    ) : (
                        '—'
                    ),
            },
            {
                accessorKey: 'updated_at',
                header: t('Last change'),
                cell: ({ row }) => (
                    <span className="text-sm whitespace-nowrap text-muted-foreground">
                        {formatDateTime(row.original.updated_at)}
                    </span>
                ),
            },
        ],
        [t],
    );

    const table = useReactTable({
        data: parcels,
        columns,
        getRowId: (parcel) => String(parcel.id),
        getCoreRowModel: getCoreRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        globalFilterFn: (row, _columnId, value: string) => {
            const needle = value.trim().toLowerCase();

            if (!needle) {
                return true;
            }

            return [
                row.original.tracking_number,
                row.original.reference,
                row.original.customer_name,
                row.original.customer_phone,
                row.original.customer_city,
            ].some((field) => field?.toLowerCase().includes(needle));
        },
        state: { globalFilter: search },
        onGlobalFilterChange: setSearch,
    });

    return (
        <DataTableCard className="mt-4">
            <DataTableCardToolbar className="flex items-center justify-between gap-3">
                <div className="relative w-full max-w-xs">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('Search parcels…')}
                        className="pl-8"
                    />
                </div>
                <span className="text-sm whitespace-nowrap text-muted-foreground">
                    {loading ? (
                        <Spinner className="size-4" />
                    ) : (
                        t(':count parcels', {
                            count: table.getFilteredRowModel().rows.length,
                        })
                    )}
                </span>
            </DataTableCardToolbar>

            <DataTableCardTable>
                <DataTable
                    table={table}
                    columnCount={columns.length}
                    emptyIcon={<Truck />}
                    emptyMessage={t('No parcels yet')}
                    emptyDescription={t(
                        'Parcels handed to you will show up here.',
                    )}
                />
            </DataTableCardTable>
        </DataTableCard>
    );
}
