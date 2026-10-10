import { Head, usePage } from '@inertiajs/react';
import {
    CircleX,
    ClipboardList,
    Clock,
    HandCoins,
    Hourglass,
    PackageCheck,
    PhoneCall,
    TrendingUp,
    Undo2,
} from 'lucide-react';
import type { ComponentType, PointerEvent, ReactNode } from 'react';
import { useState } from 'react';
import { PeriodFilter } from '@/components/dashboard/dashboard-filters';
import type {
    DashboardFilterValues,
    PeriodOption,
} from '@/components/dashboard/dashboard-filters';
import type {
    Bucket,
    PerformanceTrackData,
} from '@/components/dashboard/performance-track';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateRange } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { PageProps } from '@/types';

type AgentFilterValues = Pick<
    DashboardFilterValues,
    'period' | 'date_from' | 'date_to'
>;

const FILTER_KEYS: (keyof AgentFilterValues)[] = [
    'period',
    'date_from',
    'date_to',
];

const PERIODS: PeriodOption[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'this_month', label: 'This month' },
    { value: '90d', label: 'Last 90 days' },
    { value: 'custom', label: 'Custom range…' },
];

type Props = {
    filters: AgentFilterValues;
    performance: PerformanceTrackData;
    /** Length of the resolved window, in days. */
    periodDays: number;
    totals: {
        assigned: number;
        confirmed: number;
        delivered: number;
        cancelled: number;
    };
    tileRates: {
        confirmed: number | null;
        delivered: number | null;
        cancelled: number | null;
    };
    confirmationRateTrend: { date: string; rate: number | null }[];
    confirmationRateTarget: string | null;
    deliveryRateTrend: { date: string; rate: number | null }[];
    deliveryRateTarget: string | null;
    rateTotals: {
        confirmation: number | null;
        delivery: number | null;
    };
    commissionEarned: number;
    upsells: { count: number; rate: number | null };
    /** Hour of day (0–23) with the most confirmations, null when none. */
    bestConfirmHour: number | null;
};

/* Semantic colours from the preview — never repainted. */
const TONE = {
    teal: 'bg-[#16A08E]/10 text-[#0C7D6F]',
    petrol: 'bg-[#468FA5]/12 text-[#2E7389]',
    plum: 'bg-secondary text-secondary-foreground',
    amber: 'bg-[#EFA22C]/12 text-[#A87110]',
    orange: 'bg-[#F2602F]/10 text-[#C24A1A]',
    red: 'bg-[#D92D20]/10 text-[#B3281D]',
} as const;

const n = (value: number) => value.toLocaleString();

export default function AgentDashboard({
    filters,
    performance,
    totals,
    tileRates,
    rateTotals,
    commissionEarned,
    upsells,
    bestConfirmHour,
}: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const firstName = auth.user?.name?.split(' ')[0] ?? '';

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(dashboard().url, filters, FILTER_KEYS);

    const rangeLabel =
        filters.date_from && filters.date_to
            ? formatDateRange(filters.date_from, filters.date_to)
            : t(
                  PERIODS.find((p) => p.value === (filters.period ?? '30d'))
                      ?.label ?? 'Last 30 days',
              );

    // Orders still being worked are the ones neither confirmed nor
    // cancelled yet; the stats table stores only the two outcomes.
    const inProgress = Math.max(
        0,
        totals.assigned - totals.confirmed - totals.cancelled,
    );
    const [returned, returnedPct] = performance.outcomes.ret;

    const rates: { label: string; value: number | null; color: string }[] = [
        {
            label: t('Confirmation rate'),
            value: rateTotals.confirmation,
            color: '#F2602F',
        },
        {
            label: t('Delivery rate'),
            value: rateTotals.delivery,
            color: '#16A08E',
        },
        {
            label: t('Cancellation rate'),
            value: tileRates.cancelled,
            color: '#D92D20',
        },
        { label: t('Returned rate'), value: returnedPct, color: '#B3281D' },
        { label: t('Upsell rate'), value: upsells.rate, color: '#49183C' },
    ];

    const bestTime =
        bestConfirmHour === null
            ? '—'
            : `${String(bestConfirmHour).padStart(2, '0')}h–${String((bestConfirmHour + 1) % 24).padStart(2, '0')}h`;

    return (
        <>
            <Head title={t('Dashboard')} />

            <div className="pb-10">
                {/* page header + the one filter: date */}
                <div className="flex flex-wrap items-end justify-between gap-4 px-4 pt-7 lg:px-6">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Salam, :name 👋', { name: firstName })}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Your confirmation work, in one place')} ·{' '}
                            <span className="font-medium text-foreground">
                                {rangeLabel}
                            </span>
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <PeriodFilter
                            draft={draft}
                            onChange={updateFilters}
                            periods={PERIODS}
                            idPrefix="agent"
                        />
                        {hasActiveFilters && (
                            <DataTableResetFiltersButton
                                onReset={resetFilters}
                            />
                        )}
                    </div>
                </div>

                <section className="mx-auto w-full max-w-2xl px-4 pt-6 lg:max-w-none lg:px-6">
                    {/* hero: assigned */}
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-xs">
                        <div className="flex items-center gap-3">
                            <span
                                className={cn(
                                    'flex size-11 shrink-0 items-center justify-center rounded-full',
                                    TONE.teal,
                                )}
                            >
                                <ClipboardList className="size-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-muted-foreground">
                                    {t('Orders assigned to me')}
                                </p>
                                <p className="font-mono text-[34px] leading-tight font-semibold tabular-nums">
                                    {n(totals.assigned)}
                                </p>
                            </div>
                            <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                                {rangeLabel}
                            </span>
                        </div>
                    </div>

                    <div className="lg:grid lg:grid-cols-[3fr_2fr] lg:gap-6">
                        <div>
                            <SectionLabel>
                                {t('Confirmation phase')}
                            </SectionLabel>
                            <div className="grid grid-cols-3 gap-3 lg:gap-4">
                                <KpiCard
                                    icon={Clock}
                                    tone={TONE.amber}
                                    value={n(inProgress)}
                                    label={t('In progress')}
                                />
                                <KpiCard
                                    icon={PhoneCall}
                                    tone={TONE.orange}
                                    value={n(totals.confirmed)}
                                    label={t('Confirmed')}
                                />
                                <KpiCard
                                    icon={CircleX}
                                    tone={TONE.red}
                                    value={n(totals.cancelled)}
                                    label={t('Cancelled')}
                                />
                            </div>
                        </div>
                        <div>
                            <SectionLabel>{t('Delivery phase')}</SectionLabel>
                            <div className="grid grid-cols-2 gap-3 lg:gap-4">
                                <KpiCard
                                    icon={PackageCheck}
                                    tone={TONE.teal}
                                    value={n(totals.delivered)}
                                    label={t('Delivered')}
                                />
                                <KpiCard
                                    icon={Undo2}
                                    tone={TONE.red}
                                    value={n(returned)}
                                    label={t('Returned')}
                                />
                            </div>
                        </div>
                    </div>

                    {totals.assigned === 0 && (
                        <p className="pt-6 text-center text-sm text-muted-foreground">
                            {t(
                                'No orders in this period — pick a wider range. 🎉',
                            )}
                        </p>
                    )}

                    {/* performance track · orders chart */}
                    <SectionLabel className="mt-7">
                        {t('Performance track')}
                    </SectionLabel>
                    <OrdersTrend
                        buckets={performance.daily}
                        rangeLabel={rangeLabel}
                    />

                    {/* my rates */}
                    <div className="mt-4 rounded-2xl border border-border bg-card p-4 shadow-xs lg:p-5">
                        <p className="text-sm font-medium text-muted-foreground">
                            {t('My rates')} · {rangeLabel}
                        </p>
                        <div className="mt-4 space-y-4">
                            {rates.map((rate) => (
                                <div key={rate.label}>
                                    <div className="mb-1.5 flex items-baseline justify-between">
                                        <span className="text-sm font-medium">
                                            {rate.label}
                                        </span>
                                        <span
                                            className="font-mono text-sm font-semibold tabular-nums"
                                            style={{ color: rate.color }}
                                        >
                                            {rate.value === null
                                                ? '—'
                                                : `${rate.value}%`}
                                        </span>
                                    </div>
                                    <div className="h-8 overflow-hidden rounded-full bg-muted/50">
                                        <div
                                            className="h-full rounded-full transition-all duration-500"
                                            style={{
                                                width: `${Math.min(100, rate.value ?? 0)}%`,
                                                background: rate.color,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* upsells · commissions · best time */}
                    <div className="mt-4 grid grid-cols-3 gap-3 lg:gap-4">
                        <KpiCard
                            icon={TrendingUp}
                            tone={TONE.plum}
                            value={n(upsells.count)}
                            label={t('Upsells')}
                        />
                        <KpiCard
                            icon={HandCoins}
                            tone={TONE.amber}
                            value={
                                <>
                                    {n(Math.round(commissionEarned))}{' '}
                                    <span className="font-sans text-xs font-normal text-muted-foreground">
                                        MAD
                                    </span>
                                </>
                            }
                            label={t('Commissions')}
                            compact
                        />
                        <KpiCard
                            icon={Hourglass}
                            tone={TONE.petrol}
                            value={bestTime}
                            label={t('Best confirm time')}
                            compact
                        />
                    </div>
                </section>
            </div>
        </>
    );
}

function SectionLabel({
    children,
    className,
}: {
    children: string;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'mt-5 mb-2 px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase',
                className,
            )}
        >
            {children}
        </p>
    );
}

function KpiCard({
    icon: Icon,
    tone,
    value,
    label,
    compact = false,
}: {
    icon: ComponentType<{ className?: string }>;
    tone: string;
    value: ReactNode;
    label: string;
    compact?: boolean;
}) {
    return (
        <div className="flex flex-col items-start rounded-2xl border border-border bg-card p-4 shadow-xs">
            <span
                className={cn(
                    'flex size-9 items-center justify-center rounded-full',
                    tone,
                )}
            >
                <Icon className="size-[18px]" />
            </span>
            <p
                className={cn(
                    'mt-3 font-mono leading-none font-semibold tabular-nums',
                    compact ? 'text-xl lg:text-2xl' : 'text-2xl',
                )}
            >
                {value}
            </p>
            <p className="mt-1.5 text-xs font-medium text-foreground">
                {label}
            </p>
        </div>
    );
}

/** Orders assigned over time: an area line, with a tap/drag scrubber. */
function OrdersTrend({
    buckets,
    rangeLabel,
}: {
    buckets: Bucket[];
    rangeLabel: string;
}) {
    const { t } = useTranslation();
    const [active, setActive] = useState<number | null>(null);

    const vals = buckets.map((b) => b.value);
    const total = vals.reduce((a, b) => a + b, 0);
    const W = 1000;
    const H = 240;
    const PAD = 8;
    const max = Math.max(1, ...vals);
    const x = (i: number) =>
        vals.length === 1 ? W / 2 : i * (W / (vals.length - 1));
    const y = (v: number) => H - PAD - (v / max) * (H - PAD * 2);

    let line = '';
    let area = `M0,${H} `;
    vals.forEach((v, i) => {
        line += `${i ? 'L' : 'M'}${x(i)},${y(v)} `;
        area += `L${x(i)},${y(v)} `;
    });
    area += `L${W},${H} Z`;

    const peak = vals.length ? vals.indexOf(Math.max(...vals)) : -1;
    const ticks =
        buckets.length <= 2
            ? buckets
            : [
                  buckets[0],
                  buckets[Math.floor((buckets.length - 1) / 2)],
                  buckets[buckets.length - 1],
              ];

    const scrub = (e: PointerEvent<HTMLDivElement>) => {
        if (!vals.length) {
            return;
        }

        const r = e.currentTarget.getBoundingClientRect();
        const fx = Math.min(Math.max(e.clientX - r.left, 0), r.width);
        setActive(Math.round((fx / r.width) * (vals.length - 1)));
    };

    const activePct =
        active === null
            ? 0
            : vals.length === 1
              ? 50
              : (active / (vals.length - 1)) * 100;

    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-xs lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-sm font-medium text-muted-foreground">
                        {t('Orders assigned over time')}
                    </p>
                    <p className="mt-0.5">
                        <span className="font-mono text-2xl font-semibold tabular-nums">
                            {n(total)}
                        </span>{' '}
                        <span className="text-xs text-muted-foreground">
                            {t('orders')} · {rangeLabel}
                        </span>
                    </p>
                </div>
                {peak >= 0 && (
                    <span className="rounded-full bg-[#16A08E]/10 px-2.5 py-1 font-mono text-xs font-semibold text-[#0C7D6F]">
                        {t('peak')} {n(vals[peak])} /{t('day')} ·{' '}
                        {buckets[peak].label}
                    </span>
                )}
            </div>
            <div
                className="relative mt-3 touch-pan-y"
                onPointerDown={scrub}
                onPointerMove={(e) => {
                    if (e.pressure > 0 || e.pointerType === 'mouse') {
                        scrub(e);
                    }
                }}
                onPointerLeave={() => setActive(null)}
                onPointerUp={() => setTimeout(() => setActive(null), 1200)}
            >
                <svg
                    viewBox={`0 0 ${W} ${H}`}
                    preserveAspectRatio="none"
                    className="h-44 w-full lg:h-56"
                >
                    {vals.length === 1 ? (
                        <rect
                            x={W / 2 - 60}
                            y={vals[0] ? y(vals[0]) : H - PAD - 2}
                            width={120}
                            height={vals[0] ? H - PAD - y(vals[0]) : 2}
                            rx={3}
                            fill="#16A08E"
                        />
                    ) : (
                        <>
                            <path d={area} fill="#16A08E" opacity={0.08} />
                            <path
                                d={line}
                                fill="none"
                                stroke="#16A08E"
                                strokeWidth={2.5}
                                vectorEffect="non-scaling-stroke"
                            />
                            {peak >= 0 && (
                                <circle
                                    cx={x(peak)}
                                    cy={y(vals[peak])}
                                    r={4.5}
                                    fill="#16A08E"
                                    vectorEffect="non-scaling-stroke"
                                />
                            )}
                        </>
                    )}
                </svg>
                {active !== null && (
                    <>
                        <div
                            className="pointer-events-none absolute top-0 h-full w-px bg-foreground/30"
                            style={{ left: `${activePct}%` }}
                        />
                        <div
                            className="pointer-events-none absolute top-1 -translate-x-1/2 rounded-md bg-foreground px-2 py-1 font-mono text-[11px] font-semibold text-background shadow"
                            style={{
                                left: `clamp(34px, ${activePct}%, calc(100% - 34px))`,
                            }}
                        >
                            {buckets[active].label} · {n(vals[active])}
                        </div>
                    </>
                )}
            </div>
            <div className="mt-1.5 flex justify-between font-mono text-[10px] text-muted-foreground">
                {ticks.map((b, i) => (
                    <span key={i}>{b.label}</span>
                ))}
            </div>
        </div>
    );
}

AgentDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
