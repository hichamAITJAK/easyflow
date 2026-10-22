import { Head } from '@inertiajs/react';
import {
    getCoreRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import type { SortingState } from '@tanstack/react-table';
import { Banknote, PackageCheck, Truck, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    PeriodFilter,
    DEFAULT_PERIOD,
} from '@/components/dashboard/dashboard-filters';
import type { DashboardFilterValues } from '@/components/dashboard/dashboard-filters';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFilters,
    DataTableCardTable,
} from '@/components/data-table/data-table-card';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import Heading from '@/components/heading';
import {
    PILL_TABS_COUNT,
    PILL_TABS_LIST,
    PILL_TABS_TRIGGER,
} from '@/components/pill-tabs';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as cashIndex } from '@/routes/finance/cash';
import { createColumns } from './columns';
import type { CashRow } from './columns';

type CashTab = 'couriers' | 'guys';

type CashFilters = Pick<
    DashboardFilterValues,
    'period' | 'date_from' | 'date_to'
>;

type Props = {
    filters: CashFilters;
    couriers: CashRow[];
    deliveryGuys: CashRow[];
};

function money(value: number): string {
    return formatNumber(Math.round(value * 100) / 100);
}

/**
 * A figure above the table. Period figures say so in their caption, so
 * the running balance next to them is never read as a period total.
 */
function Figure({
    label,
    caption,
    value,
    unit,
    icon: Icon,
    tone,
}: {
    label: string;
    caption: string;
    value: string;
    unit?: string;
    icon: LucideIcon;
    tone?: 'destructive';
}) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-border bg-card p-4 shadow-xs">
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary text-primary',
                    tone === 'destructive' &&
                        'bg-destructive/10 text-destructive',
                )}
            >
                <Icon className="size-[18px]" />
            </span>
            <div className="min-w-0">
                <p className="text-sm font-medium">{label}</p>
                <p className="mt-1 leading-none">
                    <span
                        className={cn(
                            'font-mono text-2xl font-semibold tabular-nums',
                            tone === 'destructive' && 'text-destructive',
                        )}
                    >
                        {value}
                    </span>
                    {unit && (
                        <span className="ml-1 text-xs text-muted-foreground">
                            {unit}
                        </span>
                    )}
                </p>
                <p className="mt-1.5 text-xs text-muted-foreground">
                    {caption}
                </p>
            </div>
        </div>
    );
}

function CashTable({
    rows,
    kind,
    emptyTitle,
    emptyDescription,
}: {
    rows: CashRow[];
    kind: CashTab;
    emptyTitle: string;
    emptyDescription: string;
}) {
    const { t } = useTranslation();
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'stillOwedMad', desc: true },
    ]);

    const columns = useMemo(() => createColumns({ t, kind }), [t, kind]);

    const table = useReactTable({
        data: rows,
        columns,
        state: { sorting },
        onSortingChange: setSorting,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
    });

    if (rows.length === 0) {
        return (
            <Empty className="border">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <Truck />
                    </EmptyMedia>
                    <EmptyTitle>{emptyTitle}</EmptyTitle>
                    <EmptyDescription>{emptyDescription}</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <DataTableCard>
            <DataTableCardTable>
                <DataTable table={table} columnCount={columns.length} />
            </DataTableCardTable>
        </DataTableCard>
    );
}

export default function CashTracking({
    filters,
    couriers,
    deliveryGuys,
}: Props) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<CashTab>('couriers');

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters<CashFilters>(cashIndex().url, filters, [
            'period',
            'date_from',
            'date_to',
        ]);

    const rows = tab === 'couriers' ? couriers : deliveryGuys;

    const totals = useMemo(() => {
        const shipped = rows.reduce((sum, row) => sum + row.shipped, 0);
        const delivered = rows.reduce((sum, row) => sum + row.delivered, 0);

        return {
            owed: rows.reduce(
                (sum, row) => sum + Math.max(0, row.stillOwedMad),
                0,
            ),
            collected: rows.reduce((sum, row) => sum + row.collectedMad, 0),
            delivered,
            rate: shipped > 0 ? Math.round((delivered / shipped) * 100) : null,
        };
    }, [rows]);

    return (
        <>
            <Head title={t('Cash tracking')} />

            <div className="space-y-6 p-4">
                <Heading
                    className="mb-0"
                    title={t('Cash tracking')}
                    description={t(
                        'Where your cash-on-delivery money is: with your couriers and your own delivery guys.',
                    )}
                />

                <Tabs
                    value={tab}
                    onValueChange={(value) => setTab(value as CashTab)}
                >
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <TabsList className={PILL_TABS_LIST}>
                            <TabsTrigger
                                value="couriers"
                                className={PILL_TABS_TRIGGER}
                            >
                                <span>{t('Couriers')}</span>
                                <span className={PILL_TABS_COUNT}>
                                    {couriers.length}
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="guys"
                                className={PILL_TABS_TRIGGER}
                            >
                                <span>{t('Delivery guys')}</span>
                                <span className={PILL_TABS_COUNT}>
                                    {deliveryGuys.length}
                                </span>
                            </TabsTrigger>
                        </TabsList>

                        <DataTableCardFilters className="border-0 p-0">
                            <PeriodFilter
                                idPrefix="cash"
                                draft={{
                                    period: draft.period ?? DEFAULT_PERIOD,
                                    date_from: draft.date_from,
                                    date_to: draft.date_to,
                                }}
                                onChange={(next) => updateFilters(next)}
                            />
                            {hasActiveFilters && (
                                <DataTableResetFiltersButton
                                    onReset={resetFilters}
                                />
                            )}
                        </DataTableCardFilters>
                    </div>

                    {(['couriers', 'guys'] as const).map((value) => (
                        <TabsContent
                            key={value}
                            value={value}
                            className="space-y-4 pt-4"
                        >
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <Figure
                                    label={t('Still owed')}
                                    caption={t(
                                        'Running balance, not tied to the period',
                                    )}
                                    value={money(totals.owed)}
                                    unit="MAD"
                                    icon={Wallet}
                                    tone={
                                        totals.owed > 0
                                            ? 'destructive'
                                            : undefined
                                    }
                                />
                                <Figure
                                    label={t('Collected')}
                                    caption={t('Delivered in the period')}
                                    value={money(totals.collected)}
                                    unit="MAD"
                                    icon={Banknote}
                                />
                                <Figure
                                    label={t('Delivered')}
                                    caption={t('Parcels in the period')}
                                    value={formatNumber(totals.delivered)}
                                    icon={PackageCheck}
                                />
                                <Figure
                                    label={t('Delivery rate')}
                                    caption={t(
                                        'Delivered over shipped, in the period',
                                    )}
                                    value={
                                        totals.rate === null
                                            ? '—'
                                            : `${totals.rate}%`
                                    }
                                    icon={Truck}
                                />
                            </div>

                            <CashTable
                                rows={
                                    value === 'couriers'
                                        ? couriers
                                        : deliveryGuys
                                }
                                kind={value}
                                emptyTitle={
                                    value === 'couriers'
                                        ? t('No courier connected yet')
                                        : t('No delivery guys yet')
                                }
                                emptyDescription={
                                    value === 'couriers'
                                        ? t(
                                              'Connect a delivery courier to start tracking its cash here.',
                                          )
                                        : t(
                                              'Add delivery guys to your team to track the cash they hold.',
                                          )
                                }
                            />
                        </TabsContent>
                    ))}
                </Tabs>
            </div>
        </>
    );
}

CashTracking.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Cash tracking', href: cashIndex() },
    ],
};
