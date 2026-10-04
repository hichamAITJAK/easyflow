import { Head, Link, router } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowLeft, MapPin, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    cities as courierCities,
    index as couriersIndex,
    syncCities,
} from '@/routes/super-admin/couriers';
import type { CourierCity, CourierCityFilters, Paginated } from '@/types';

const FILTER_KEYS: (keyof CourierCityFilters)[] = ['search'];

export default function SuperAdminCourierCities({
    courier,
    cities,
    filters,
}: {
    courier: { id: number; name: string; slug: string; syncable: boolean };
    cities: Paginated<CourierCity>;
    filters: CourierCityFilters;
}) {
    const { t } = useTranslation();

    const routeUrl = courierCities(courier.id).url;

    const { updateFilters } = useTableFilters(routeUrl, filters, FILTER_KEYS);

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstSearchRun = useRef(true);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        updateFilters({
            search: debouncedSearch || undefined,
            page: undefined,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const columns = useMemo<ColumnDef<CourierCity>[]>(() => {
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
                accessorKey: 'name',
                id: 'city',
                header: () => sortHeader('City', 'name'),
                cell: ({ row }) => (
                    <span className="font-medium">{row.original.name}</span>
                ),
            },
            {
                accessorKey: 'arabic_name',
                id: 'arabic name',
                header: t('Arabic name'),
                cell: ({ row }) => (
                    <span dir="rtl" className="text-muted-foreground">
                        {row.original.arabic_name ?? '—'}
                    </span>
                ),
            },
            {
                accessorKey: 'external_courrier_id',
                id: 'courier id',
                header: () =>
                    sortHeader(t('Courier ID'), 'external_courrier_id'),
                cell: ({ row }) => (
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.external_courrier_id ?? '—'}
                    </span>
                ),
            },
        ];
    }, [filters, routeUrl]);

    const table = useReactTable({
        data: cities.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <SuperAdminLayout>
            <Head title={`${courier.name} cities`} />

            <div className="space-y-6">
                <div>
                    <Link
                        href={couriersIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to couriers')}
                    </Link>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title={`${courier.name} cities`}
                        description={t(
                            'The delivery destinations tenants can pick from when creating a parcel with this courier.',
                        )}
                    />

                    {courier.syncable && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    syncCities(courier.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw />
                            {t('Sync from :name', { name: courier.name })}
                        </Button>
                    )}
                </div>

                <DataTableCard>
                    <DataTableCardToolbar>
                        <Input
                            className="w-full max-w-sm sm:w-64"
                            placeholder={t('Search city name or ID…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            aria-label={t('Search cities')}
                        />
                        <div className="flex items-center gap-2">
                            <DataTablePerPageSelect
                                routeUrl={routeUrl}
                                query={filters}
                                value={Number(filters.per_page ?? 20)}
                                size="sm"
                            />
                            <DataTableViewOptions table={table} />
                        </div>
                    </DataTableCardToolbar>

                    <DataTableCardTable>
                        <DataTable
                            table={table}
                            columnCount={columns.length}
                            emptyIcon={<MapPin />}
                            emptyMessage={
                                search
                                    ? t('No cities match this search')
                                    : t('No cities yet')
                            }
                            emptyDescription={
                                search
                                    ? t(
                                          'Try a different city name or courier ID.',
                                      )
                                    : courier.syncable
                                      ? t(
                                            "Run a sync to pull :name's covered cities.",
                                            { name: courier.name },
                                        )
                                      : t(
                                            'No cities API is wired up for :name, so its cities are managed in code.',
                                            { name: courier.name },
                                        )
                            }
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <DataTablePaginationServer paginated={cities} />
                    </DataTableCardFooter>
                </DataTableCard>
            </div>
        </SuperAdminLayout>
    );
}
