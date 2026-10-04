import { Head, router, usePage } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Upload } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ImportResult } from '@/components/customers/customer-import-dialog';
import { CustomerImportDialog } from '@/components/customers/customer-import-dialog';
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
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as customersIndex } from '@/routes/customers';
import type {
    Customer,
    CustomerFilters,
    CustomerMetrics,
    Paginated,
    PageProps,
} from '@/types';
import { createColumns } from './columns';

const ADMIN_ROLES = ['admin', 'super_admin'];

type CustomerTab = 'all' | 'best' | 'blacklisted';

const FILTER_KEYS: (keyof CustomerFilters)[] = [
    'search',
    'best',
    'blacklisted',
];

export default function CustomersIndex({
    customers,
    metrics,
    filters,
    importResult,
}: {
    customers: Paginated<Customer>;
    metrics: CustomerMetrics;
    filters: CustomerFilters;
    importResult?: ImportResult | null;
}) {
    const { t } = useTranslation();

    const tab: CustomerTab =
        filters.blacklisted === '1'
            ? 'blacklisted'
            : filters.best === '1'
              ? 'best'
              : 'all';

    const { auth } = usePage<PageProps>().props;
    // Mirrors the route's `manage-users` gate — bulk-writing client PII is
    // an owner's action, not an agent's.
    const canImport = ADMIN_ROLES.includes(auth.user.role);

    const [importOpen, setImportOpen] = useState(false);

    // Reopen with the outcome after the redirect, so the skipped-row
    // reasons land somewhere the operator can actually read them.
    useEffect(() => {
        if (importResult && importResult.errors.length > 0) {
            setImportOpen(true);
        }
    }, [importResult]);

    const { draft, updateFilters } = useTableFilters(
        customersIndex().url,
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

    const handleTabChange = (value: string) => {
        router.get(
            customersIndex().url,
            {
                ...draft,
                // The two flags are mutually exclusive tabs, so each switch
                // clears the other — leaving both set would silently AND
                // them and show an empty list.
                best: value === 'best' ? '1' : undefined,
                blacklisted: value === 'blacklisted' ? '1' : undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const columns = useMemo(
        () => createColumns({ t, filters, routeUrl: customersIndex().url }),
        [t, filters],
    );

    const table = useReactTable({
        data: customers.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <>
            <Head title={t('Customers')} />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={t('Customers')}
                        description={t(
                            'Everyone who has ever placed an order with your business.',
                        )}
                    />
                    {canImport && (
                        <Button
                            variant="outline"
                            className="shrink-0 px-3 sm:px-4"
                            onClick={() => setImportOpen(true)}
                        >
                            <Upload />
                            <span className="hidden sm:inline">
                                {t('Import CSV')}
                            </span>
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>
                                {t('Total customers')}
                            </CardDescription>
                            <CardTitle className="text-2xl">
                                {metrics.total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>
                                {t('Best clients')}
                            </CardDescription>
                            <CardTitle className="text-2xl text-amber-600 dark:text-amber-400">
                                {metrics.best}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>
                                {t('Blacklisted')}
                            </CardDescription>
                            <CardTitle className="text-2xl text-destructive">
                                {metrics.blacklisted}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <Tabs value={tab} onValueChange={handleTabChange}>
                    <TabsList>
                        <TabsTrigger value="all">
                            {t('All customers')}
                        </TabsTrigger>
                        <TabsTrigger value="best">
                            {t('Best clients')}
                        </TabsTrigger>
                        <TabsTrigger value="blacklisted">
                            {t('Blacklisted')}
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                <DataTableCard>
                    <DataTableCardToolbar>
                        <Input
                            className="max-w-sm"
                            placeholder={t('Search by city…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <div className="flex items-center gap-2">
                            <DataTablePerPageSelect
                                routeUrl={customersIndex().url}
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
                            emptyMessage={
                                tab === 'best'
                                    ? t('No best clients yet.')
                                    : tab === 'blacklisted'
                                      ? t('No blacklisted customers.')
                                      : t('No customers yet.')
                            }
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <DataTablePaginationServer paginated={customers} />
                    </DataTableCardFooter>
                </DataTableCard>
            </div>

            {canImport && (
                <CustomerImportDialog
                    open={importOpen}
                    onOpenChange={setImportOpen}
                    result={importResult}
                />
            )}
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Customers',
            href: '/customers',
        },
    ],
};
