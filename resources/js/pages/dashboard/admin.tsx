import { Head, Link, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    HandCoins,
    Info,
    PackageCheck,
    RotateCcw,
    ShoppingCart,
    TriangleAlert,
    Truck,
    Wallet,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { AgentFilter } from '@/components/dashboard/agent-filter';
import { DashboardFilters } from '@/components/dashboard/dashboard-filters';
import type {
    DashboardFilterValues,
    SimpleOption,
} from '@/components/dashboard/dashboard-filters';
import { InventoryCard } from '@/components/dashboard/inventory-card';
import type { Inventory } from '@/components/dashboard/inventory-card';
import { OrdersPerDayCard } from '@/components/dashboard/orders-per-day-card';
import type { OrdersPerDayPoint } from '@/components/dashboard/orders-per-day-card';
import { ParcelsCard } from '@/components/dashboard/parcels-card';
import type { ParcelStages } from '@/components/dashboard/parcels-card';
import { PerformanceTable } from '@/components/dashboard/performance-table';
import type { PerformanceRow } from '@/components/dashboard/performance-table';
import { StatTile } from '@/components/dashboard/stat-tile';
import { WeeklyRateLine } from '@/components/dashboard/weekly-rate-line';
import type { WeeklyRatePoint } from '@/components/dashboard/weekly-rate-line';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { formatCompactNumber, formatNumber } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as ordersIndex } from '@/routes/orders';
import { index as parcelsIndex } from '@/routes/parcels';
import type { PageProps } from '@/types';

const FILTER_KEYS: (keyof DashboardFilterValues)[] = [
    'store_ids',
    'period',
    'date_from',
    'date_to',
    'agent_id',
];

type Props = {
    filters: DashboardFilterValues;
    stores: SimpleOption[];
    agents: SimpleOption[];
    money: {
        totalEarned: number;
        totalEarnedDelta: number | null;
        commissions: number;
        commissionsDelta: number | null;
        courierExpected: number;
        courierVariance: number | null;
    };
    ordersPerDay: OrdersPerDayPoint[];
    parcels: ParcelStages;
    inventory: Inventory;
    summary: {
        orders: number;
        confirmed: number;
        delivered: number;
        returned: number;
        /** Whole percentages; null when the base is zero. */
        confirmedRate: number | null;
        deliveredRate: number | null;
        returnedRate: number | null;
    };
    targets: { confirmation: number; delivery: number };
    rates: {
        buckets: {
            confirmation: WeeklyRatePoint[];
            delivery: WeeklyRatePoint[];
            return: WeeklyRatePoint[];
        };
        totals: {
            confirmation: number | null;
            delivery: number | null;
            return: number | null;
        };
        counts: Record<
            'confirmation' | 'delivery' | 'return',
            { count: number; total: number }
        >;
    };
    /** Set only while the Rates card is filtered to one agent. */
    agentTargets: { confirmation: number; delivery: number } | null;
    performanceTable: {
        stores: PerformanceRow[];
        products: PerformanceRow[];
        couriers: PerformanceRow[];
    };
    alerts: { id: string; count: number }[];
};

function money(value: number): string {
    return formatCompactNumber(value) + ' MAD';
}

/**
 * How far a rate sits from its target, e.g. "+4 vs 80% target". Green at
 * or above, red below; "—" when there is no rate to compare.
 */
function TargetGap({ rate, target }: { rate: number | null; target: number }) {
    const { t } = useTranslation();

    if (rate === null) {
        return (
            <dd className="text-xs text-muted-foreground tabular-nums">
                {t('— vs :target% target', { target: Math.round(target) })}
            </dd>
        );
    }

    const gap = Math.round(rate - target);

    return (
        <dd
            className={cn(
                'text-xs font-medium tabular-nums',
                gap >= 0 ? 'text-success' : 'text-destructive',
            )}
        >
            {t(':gap vs :target% target', {
                gap: `${gap > 0 ? '+' : ''}${gap}`,
                target: Math.round(target),
            })}
        </dd>
    );
}

function greeting(t: Translator): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return t('Good morning');
    }

    if (hour < 18) {
        return t('Good afternoon');
    }

    return t('Good evening');
}

/**
 * Alert content templates keyed by the server's alert id — the server
 * sends facts (id + count), the page owns copy and routing.
 */
const ALERT_TEMPLATES: Record<
    string,
    {
        variant: 'warning' | 'info';
        title: (count: number, t: Translator) => string;
        description: string;
        actionLabel: string;
        href: () => string;
        dismissible: boolean;
    }
> = {
    unassigned: {
        variant: 'warning',
        title: (count, t) =>
            count === 1
                ? t(':count order unassigned', { count })
                : t(':count orders unassigned', { count }),
        description:
            'No eligible agent covers these orders — assign them manually before they go stale.',
        actionLabel: 'Assign now',
        href: () => ordersIndex({ query: { confirmation_status: 'new' } }).url,
        dismissible: false,
    },
    'stale-transit': {
        variant: 'info',
        title: (count, t) =>
            count === 1
                ? t(':count parcel stuck in transit', { count })
                : t(':count parcels stuck in transit', { count }),
        description:
            'No courier status change in 5 days. Worth a call to the courier.',
        actionLabel: 'View parcels',
        href: () => parcelsIndex().url,
        dismissible: true,
    },
};

const ALERT_ICONS = {
    warning: TriangleAlert,
    destructive: CircleAlert,
    info: Info,
} as const;

export default function AdminDashboard({
    filters,
    stores,
    agents,
    money: moneyProps,
    ordersPerDay,
    parcels,
    inventory,
    summary,
    targets,
    rates,
    agentTargets,
    performanceTable,
    alerts,
}: Props) {
    const { t } = useTranslation();

    const { auth } = usePage<PageProps>().props;
    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(dashboard().url, filters, FILTER_KEYS);

    const firstName = auth.user.name.split(' ')[0];

    // Session-local: dismissed info alerts return on reload, which is fine —
    // dismissal is "quiet this for now", not a durable acknowledgement.
    const [dismissedAlerts, setDismissedAlerts] = useState<string[]>([]);

    const visibleAlerts = alerts
        .filter((alert) => !dismissedAlerts.includes(alert.id))
        .map((alert) => ({ ...alert, template: ALERT_TEMPLATES[alert.id] }))
        .filter((alert) => alert.template !== undefined);

    return (
        <>
            <Head title={t('Dashboard')} />

            <div className="space-y-6 p-4">
                {/* The period preset in the filter bar names the window, so
                    no separate "Last N days" caption. */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="grid gap-1">
                        <h1 className="text-xl font-semibold">
                            {greeting(t)}, {firstName}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t("Here's how the business is doing.")}
                        </p>
                    </div>

                    <DashboardFilters
                        stores={stores}
                        draft={draft}
                        onChange={updateFilters}
                        onReset={resetFilters}
                        hasActiveFilters={hasActiveFilters}
                    />
                </div>

                {/* Alerts — server sends id + count; templates own the copy */}
                {visibleAlerts.length > 0 && (
                    <div className="grid gap-2">
                        {visibleAlerts.map(({ id, count, template }) => {
                            const AlertIcon = ALERT_ICONS[template.variant];

                            return (
                                <Alert key={id} variant={template.variant}>
                                    <AlertIcon />
                                    <AlertTitle>
                                        {template.title(count, t)}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {t(template.description)}
                                    </AlertDescription>
                                    <AlertAction className="flex items-center gap-1">
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={template.href()}>
                                                {t(template.actionLabel)}
                                            </Link>
                                        </Button>
                                        {template.dismissible && (
                                            <Button
                                                size="icon-sm"
                                                variant="ghost"
                                                aria-label={t(
                                                    'Dismiss ":title"',
                                                    {
                                                        title: template.title(
                                                            count,
                                                            t,
                                                        ),
                                                    },
                                                )}
                                                onClick={() =>
                                                    setDismissedAlerts(
                                                        (prev) => [...prev, id],
                                                    )
                                                }
                                            >
                                                <X />
                                            </Button>
                                        )}
                                    </AlertAction>
                                </Alert>
                            );
                        })}
                    </div>
                )}

                {/* The period at a glance: count on top, its share under. */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatTile
                        label={t('Orders')}
                        caption={t('In this period')}
                        value={formatNumber(summary.orders)}
                        icon={ShoppingCart}
                    />
                    <StatTile
                        label={t('Confirmed orders')}
                        caption={
                            summary.confirmedRate === null
                                ? '—'
                                : t(':rate% of orders', {
                                      rate: summary.confirmedRate,
                                  })
                        }
                        value={formatNumber(summary.confirmed)}
                        icon={CircleCheck}
                    />
                    <StatTile
                        label={t('Delivered orders')}
                        caption={
                            summary.deliveredRate === null
                                ? '—'
                                : t(':rate% of shipped', {
                                      rate: summary.deliveredRate,
                                  })
                        }
                        value={formatNumber(summary.delivered)}
                        icon={PackageCheck}
                    />
                    <StatTile
                        label={t('Returned orders')}
                        caption={
                            summary.returnedRate === null
                                ? '—'
                                : t(':rate% of delivered', {
                                      rate: summary.returnedRate,
                                  })
                        }
                        value={formatNumber(summary.returned)}
                        icon={RotateCcw}
                    />
                </div>

                {/* Where the period's parcels are now + rate quality */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <ParcelsCard stages={parcels} />

                    <Card className="shadow-none lg:col-span-2">
                        <CardHeader className="flex flex-wrap items-start justify-between gap-2 space-y-0">
                            <div className="grid gap-1.5">
                                <CardTitle>{t('Rates')}</CardTitle>
                                <CardDescription>
                                    {t(
                                        'Confirmation, delivery and return over the selected period',
                                    )}
                                </CardDescription>
                            </div>
                            <AgentFilter
                                agents={agents}
                                value={draft.agent_id}
                                onChange={(next) =>
                                    updateFilters({ agent_id: next })
                                }
                                ariaLabel={t('Filter rates by agent')}
                            />
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Window totals, computed server-side from
                                summed counts. These answer "how is the
                                business doing" at a glance; the lines
                                below answer "which way is it moving". */}
                            <dl className="grid grid-cols-3 gap-3">
                                {(
                                    [
                                        {
                                            key: 'confirmation',
                                            label: t('Confirmation'),
                                            color: 'var(--color-chart-1)',
                                        },
                                        {
                                            key: 'delivery',
                                            label: t('Delivery'),
                                            color: 'var(--color-chart-3)',
                                        },
                                        {
                                            key: 'return',
                                            label: t('Return'),
                                            color: 'var(--color-chart-2)',
                                        },
                                    ] as const
                                ).map((tile) => (
                                    <div
                                        key={tile.key}
                                        className="grid gap-0.5"
                                    >
                                        <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <span
                                                aria-hidden
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    background: tile.color,
                                                }}
                                            />
                                            {tile.label}
                                        </dt>
                                        <dd className="text-2xl font-semibold tabular-nums">
                                            {rates.totals[tile.key] === null
                                                ? '—'
                                                : `${Math.round(rates.totals[tile.key] as number)}%`}
                                        </dd>
                                        {/* With an agent picked, the gap to
                                            their target replaces the raw
                                            counts; return has no target. */}
                                        {agentTargets &&
                                        tile.key !== 'return' ? (
                                            <TargetGap
                                                rate={rates.totals[tile.key]}
                                                target={agentTargets[tile.key]}
                                            />
                                        ) : (
                                            <dd className="text-xs text-muted-foreground tabular-nums">
                                                {formatNumber(
                                                    rates.counts[tile.key]
                                                        .count,
                                                )}{' '}
                                                /{' '}
                                                {formatNumber(
                                                    rates.counts[tile.key]
                                                        .total,
                                                )}
                                            </dd>
                                        )}
                                    </div>
                                ))}
                            </dl>
                            <WeeklyRateLine
                                series={[
                                    {
                                        key: 'confirmation',
                                        label: t('Confirmation rate'),
                                        color: 'var(--color-chart-1)',
                                        data: rates.buckets.confirmation,
                                    },
                                    {
                                        key: 'delivery',
                                        label: t('Delivery success'),
                                        color: 'var(--color-chart-3)',
                                        data: rates.buckets.delivery,
                                    },
                                ]}
                                className="h-48 w-full"
                            />
                            {/* Return rate gets its own strip and scale —
                                on a shared 0-100 axis its 6→10% drift (the
                                COD danger signal) would be invisible.
                                Rising is bad here, hence the hot accent. */}
                            <div>
                                <p className="mb-1 text-xs font-medium text-muted-foreground">
                                    {t('Return rate — lower is better')}
                                </p>
                                <WeeklyRateLine
                                    series={[
                                        {
                                            key: 'return',
                                            label: t('Return rate'),
                                            color: 'var(--color-chart-2)',
                                            data: rates.buckets.return,
                                        },
                                    ]}
                                    className="h-24 w-full"
                                />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Volume over time, on its own full-width row */}
                <OrdersPerDayCard orders={ordersPerDay} />

                {/* Money row. Courier money merges expected remittance and
                    the latest settlement variance: same story, money
                    sitting at couriers. */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                    <StatTile
                        label={t('Total earned')}
                        caption="Delivered & collected"
                        value={money(moneyProps.totalEarned)}
                        exactValue={`${formatNumber(moneyProps.totalEarned)} MAD`}
                        icon={Wallet}
                        delta={moneyProps.totalEarnedDelta ?? undefined}
                    />

                    <StatTile
                        label={t('Courier money')}
                        caption={
                            moneyProps.courierVariance === null
                                ? t('Awaiting remittance')
                                : moneyProps.courierVariance < 0
                                  ? t(':amount vs received last settlement', {
                                        amount: money(
                                            moneyProps.courierVariance,
                                        ),
                                    })
                                  : t('Settled in full last period')
                        }
                        captionAccent={
                            moneyProps.courierVariance === null
                                ? undefined
                                : moneyProps.courierVariance < 0
                                  ? 'destructive'
                                  : 'success'
                        }
                        value={money(moneyProps.courierExpected)}
                        exactValue={`${formatNumber(moneyProps.courierExpected)} MAD`}
                        icon={Truck}
                    />

                    <StatTile
                        label={t('Agent commissions')}
                        caption={t('Owed this period')}
                        value={money(moneyProps.commissions)}
                        exactValue={`${formatNumber(moneyProps.commissions)} MAD`}
                        icon={HandCoins}
                        delta={moneyProps.commissionsDelta ?? undefined}
                        higherIsBetter={false}
                    />
                </div>

                {/* What sells — own row so image + name + metrics breathe */}
                <PerformanceTable
                    stores={performanceTable.stores}
                    products={performanceTable.products}
                    couriers={performanceTable.couriers}
                    targets={targets}
                />

                {/* Stock running low, last: an action list, not a headline */}
                <InventoryCard inventory={inventory} />
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
