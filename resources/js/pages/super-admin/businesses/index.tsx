import { Head, Link, router } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Building2, Plus } from 'lucide-react';
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
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { BusinessStatusDialog } from '@/components/super-admin/business-status-dialog';
import type { BusinessStatusIntent } from '@/components/super-admin/business-status-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    create as createBusiness,
    edit as editBusiness,
    index as businessesIndex,
    show as showBusiness,
    status as businessStatus,
} from '@/routes/super-admin/businesses';
import type {
    Business,
    BusinessFilters,
    BusinessStatus,
    Paginated,
} from '@/types';
import { createColumns } from './columns';

/**
 * Select can't hold an empty-string value, so "any status" needs a sentinel
 * that maps back to an absent `status` query param.
 */
const ANY_STATUS = 'all';

const STATUS_OPTIONS: { value: BusinessStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'cancelled', label: 'Cancelled' },
];

const FILTER_KEYS: (keyof BusinessFilters)[] = ['search', 'status'];

export default function SuperAdminBusinessesIndex({
    businesses,
    filters,
}: {
    businesses: Paginated<Business>;
    filters: BusinessFilters;
}) {
    const { t } = useTranslation();

    const routeUrl = businessesIndex().url;

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(routeUrl, filters, FILTER_KEYS);

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

    // One dialog instance serves both destructive transitions; `intent`
    // picks the copy and the status it submits.
    const [statusTarget, setStatusTarget] = useState<Business | null>(null);
    const [statusIntent, setStatusIntent] =
        useState<BusinessStatusIntent>('suspend');

    const confirmStatus = (
        business: Business,
        intent: BusinessStatusIntent,
    ) => {
        setStatusTarget(business);
        setStatusIntent(intent);
    };

    const columns = useMemo(
        () =>
            createColumns({
                t,
                filters,
                routeUrl,
                onView: (business) => router.get(showBusiness(business.id)),
                onEdit: (business) => router.get(editBusiness(business.id)),
                onSuspend: (business) => confirmStatus(business, 'suspend'),
                onCancel: (business) => confirmStatus(business, 'cancel'),
                // Reactivating restores access rather than removing it, so it
                // applies straight away instead of through a confirm dialog.
                onReactivate: (business) =>
                    router.patch(
                        businessStatus(business.id),
                        { status: 'active' },
                        { preserveScroll: true, preserveState: true },
                    ),
            }),
        [filters, routeUrl],
    );

    const table = useReactTable({
        data: businesses.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    const handleReset = () => {
        setSearch('');
        resetFilters();
    };

    return (
        <SuperAdminLayout>
            <Head title={t('Businesses')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title={t('Businesses')}
                        description={t('Every tenant onboarded onto EasyFlow.')}
                    />
                    <Button asChild>
                        <Link href={createBusiness()}>
                            <Plus />
                            {t('Add business')}
                        </Link>
                    </Button>
                </div>

                <DataTableCard>
                    <DataTableCardToolbar>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                className="w-full max-w-sm sm:w-64"
                                placeholder={t('Search by name or slug…')}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                aria-label={t('Search businesses')}
                            />
                            <Select
                                value={draft.status ?? ANY_STATUS}
                                onValueChange={(value) =>
                                    updateFilters({
                                        status:
                                            value === ANY_STATUS
                                                ? undefined
                                                : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-36"
                                    aria-label={t('Filter by status')}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY_STATUS}>
                                        {t('Any status')}
                                    </SelectItem>
                                    {STATUS_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {t(option.label)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {hasActiveFilters && (
                                <DataTableResetFiltersButton
                                    onReset={handleReset}
                                />
                            )}
                        </div>

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
                            emptyIcon={<Building2 />}
                            emptyMessage={
                                hasActiveFilters
                                    ? t('No businesses match these filters')
                                    : t('No businesses yet')
                            }
                            emptyDescription={
                                hasActiveFilters
                                    ? t(
                                          'Clear the search or status filter to see every tenant.',
                                      )
                                    : 'Onboard the first tenant to get them set up with an admin account.'
                            }
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <DataTablePaginationServer paginated={businesses} />
                    </DataTableCardFooter>
                </DataTableCard>
            </div>

            <BusinessStatusDialog
                open={statusTarget !== null}
                onOpenChange={(open) => !open && setStatusTarget(null)}
                business={statusTarget}
                intent={statusIntent}
            />
        </SuperAdminLayout>
    );
}
