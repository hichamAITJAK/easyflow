import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Truck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFilters,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { parcels as deliveryParcels } from '@/routes/delivery';
import type { Paginated } from '@/types';
import type { DeliveryGuyParcel } from '@/types/delivery-guy';

const NONE = '__none__';

type Filters = {
    search?: string;
    delivery_status?: string;
    per_page?: string;
};

const FILTER_KEYS: (keyof Filters)[] = ['search', 'delivery_status'];

/**
 * Every parcel handed to the delivery guy, as the same kind of table the
 * office uses for parcels: a status filter, a search, pages.
 */
export default function DeliveryParcels({
    parcels,
    filters,
}: {
    parcels: Paginated<DeliveryGuyParcel>;
    filters: Filters;
}) {
    const { t } = useTranslation();

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(deliveryParcels().url, filters, FILTER_KEYS);

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstRun = useRef(true);

    useEffect(() => {
        if (isFirstRun.current) {
            isFirstRun.current = false;

            return;
        }

        updateFilters({ search: debouncedSearch || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const handleReset = () => {
        setSearch('');
        resetFilters();
    };

    const columns = useMemo<ColumnDef<DeliveryGuyParcel>[]>(
        () => [
            {
                accessorKey: 'tracking_number',
                header: t('Tracking number'),
                cell: ({ row }) => (
                    <div className="grid gap-0.5">
                        <span className="font-mono text-sm">
                            {row.original.tracking_number ?? '—'}
                        </span>
                        <span className="text-xs text-muted-foreground">
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
        data: parcels.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <>
            <Head title={t('Parcels')} />

            <div className="mx-auto w-full max-w-6xl space-y-4 px-4 pt-6 pb-4 lg:px-6 lg:pb-10">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('My parcels')}
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {t('Every parcel handed to you, newest first.')}
                    </p>
                </div>

                <DataTableCard>
                    <DataTableCardFilters>
                        <div className="grid gap-1.5">
                            <Label>{t('Delivery status')}</Label>
                            <Select
                                value={draft.delivery_status ?? NONE}
                                onValueChange={(value) =>
                                    updateFilters({
                                        delivery_status:
                                            value === NONE ? undefined : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue
                                        placeholder={t('All statuses')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('All statuses')}
                                    </SelectItem>
                                    {Object.entries(deliveryStatusLabels).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {t(label)}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>

                        {hasActiveFilters && (
                            <DataTableResetFiltersButton
                                onReset={handleReset}
                            />
                        )}
                    </DataTableCardFilters>

                    <DataTableCardToolbar>
                        <Input
                            className="max-w-sm"
                            placeholder={t(
                                'Search by reference, tracking number, or exact phone…',
                            )}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
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

                    <DataTableCardFooter>
                        <div className="flex w-full flex-wrap items-center justify-between gap-4">
                            <DataTablePerPageSelect
                                routeUrl={deliveryParcels().url}
                                query={filters}
                                value={Number(filters.per_page ?? 20)}
                            />
                            <DataTablePaginationServer paginated={parcels} />
                        </div>
                    </DataTableCardFooter>
                </DataTableCard>
            </div>
        </>
    );
}
