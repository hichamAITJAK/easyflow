import { Head } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { Input } from '@/components/ui/input';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { blacklist as customersBlacklist } from '@/routes/customers';
import type {
    CustomerBlacklistEntry,
    CustomerBlacklistFilters,
    Paginated,
} from '@/types';
import { createBlacklistColumns } from './blacklist-columns';

const FILTER_KEYS: (keyof CustomerBlacklistFilters)[] = ['search'];

export default function CustomersBlacklist({
    entries,
    filters,
}: {
    entries: Paginated<CustomerBlacklistEntry>;
    filters: CustomerBlacklistFilters;
}) {
    const { t } = useTranslation();

    const { updateFilters } = useTableFilters(
        customersBlacklist().url,
        filters,
        FILTER_KEYS,
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstSearchRun = useRef(true);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        updateFilters({ search: debouncedSearch || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const columns = useMemo(
        () =>
            createBlacklistColumns({
                t,
                filters,
                routeUrl: customersBlacklist().url,
            }),
        [filters],
    );

    const table = useReactTable({
        data: entries.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <>
            <Head title={t('Blacklist')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Blacklist')}
                    description={t(
                        'Phone numbers blocked from placing new orders.',
                    )}
                />

                <DataTableCard>
                    <DataTableCardToolbar>
                        <Input
                            className="max-w-sm"
                            placeholder={t('Search by phone…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <div className="flex items-center gap-2">
                            <DataTablePerPageSelect
                                routeUrl={customersBlacklist().url}
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
                            emptyMessage="No blacklisted numbers yet."
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <DataTablePaginationServer paginated={entries} />
                    </DataTableCardFooter>
                </DataTableCard>
            </div>
        </>
    );
}

CustomersBlacklist.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Blacklist',
            href: '/customers/blacklist',
        },
    ],
};
