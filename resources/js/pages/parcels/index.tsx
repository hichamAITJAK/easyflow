import { Head } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
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
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { ParcelHistoryDialog } from '@/components/parcels/parcel-history-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
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
import { deliveryStatusLabels } from '@/lib/order-status';
import { dashboard } from '@/routes';
import { index as parcelsIndex } from '@/routes/parcels';
import type {
    DeliveryAccount,
    Order,
    Paginated,
    ParcelFilters,
} from '@/types';
import { createColumns } from './columns';

const NONE = '__none__';

const FILTER_KEYS: (keyof ParcelFilters)[] = [
    'search',
    'delivery_status',
    'delivery_account_ids',
    'date_from',
    'date_to',
];

export default function ParcelsIndex({
    parcels,
    filters,
    deliveryAccounts,
}: {
    parcels: Paginated<Order>;
    filters: ParcelFilters;
    deliveryAccounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(parcelsIndex().url, filters, FILTER_KEYS);

    const selectedCouriers = draft.delivery_account_ids
        ? draft.delivery_account_ids.split(',')
        : [];

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

    const [historyOrder, setHistoryOrder] = useState<Order | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);

    const openHistory = (order: Order) => {
        setHistoryOrder(order);
        setHistoryOpen(true);
    };

    const columns = useMemo(
        () =>
            createColumns({
                t,
                filters,
                routeUrl: parcelsIndex().url,
                onViewHistory: openHistory,
            }),
        [filters, t],
    );

    const table = useReactTable({
        data: parcels.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <>
            <Head title={t('Parcels')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Parcels')}
                    description={t('Track parcels registered with your delivery couriers.')}
                />

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
                                    <SelectValue placeholder={t('All statuses')} />
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
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="parcels-courier-filter">
                                {t('Courier')}
                            </Label>
                            <MultiCombobox
                                id="parcels-courier-filter"
                                className="w-56"
                                options={deliveryAccounts.map((account) => ({
                                    value: String(account.id),
                                    label: account.courier?.name
                                        ? `${account.courier.name} — ${account.label}`
                                        : account.label,
                                }))}
                                value={selectedCouriers}
                                onChange={(next) =>
                                    updateFilters({
                                        delivery_account_ids:
                                            next.length > 0
                                                ? next.join(',')
                                                : undefined,
                                    })
                                }
                                placeholder={t('All couriers')}
                                searchPlaceholder="Search couriers…"
                                emptyMessage={t('No couriers found.')}
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="date_from">{t('From')}</Label>
                            <Input
                                id="date_from"
                                type="date"
                                className="w-40"
                                value={draft.date_from ?? ''}
                                onChange={(event) =>
                                    updateFilters({
                                        date_from:
                                            event.target.value || undefined,
                                    })
                                }
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="date_to">{t('To')}</Label>
                            <Input
                                id="date_to"
                                type="date"
                                className="w-40"
                                value={draft.date_to ?? ''}
                                onChange={(event) =>
                                    updateFilters({
                                        date_to:
                                            event.target.value || undefined,
                                    })
                                }
                            />
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
                            placeholder={t('Search by reference, tracking number, or exact phone…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <DataTableViewOptions table={table} />
                    </DataTableCardToolbar>

                    <DataTableCardTable>
                        <DataTable
                            table={table}
                            columnCount={columns.length}
                            emptyMessage={t('No parcels yet. Parcels appear here once an order is shipped with a courier.')}
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <div className="flex w-full flex-wrap items-center justify-between gap-4">
                            <DataTablePerPageSelect
                                routeUrl={parcelsIndex().url}
                                query={filters}
                                value={Number(filters.per_page ?? 20)}
                            />
                            <DataTablePaginationServer paginated={parcels} />
                        </div>
                    </DataTableCardFooter>
                </DataTableCard>
            </div>

            <ParcelHistoryDialog
                open={historyOpen}
                onOpenChange={setHistoryOpen}
                order={historyOrder}
            />
        </>
    );
}

ParcelsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Parcels',
            href: '/parcels',
        },
    ],
};
