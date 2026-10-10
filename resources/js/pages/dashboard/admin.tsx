/* ============================================================
   EASY FLOW — admin dashboard (Inertia + React + shadcn/ui)
   Mirrors the approved reference (easyflow-dashboard-preview.html),
   fed by DashboardController::adminDashboard(). Every figure comes
   from the props; nothing here is hard-coded data.
============================================================ */
import { Head, router, usePage } from '@inertiajs/react';
import {
    Box,
    CheckCircle2,
    CircleDollarSign,
    Clock,
    MessageCircle,
    Package,
    Store,
    TrendingDown,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import type { MouseEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DashboardFilters } from '@/components/dashboard/dashboard-filters';
import type {
    DashboardFilterValues,
    SimpleOption,
} from '@/components/dashboard/dashboard-filters';
import {
    C,
    money,
    n,
    PerformanceTrack,
    Ring,
    useChartTip,
} from '@/components/dashboard/performance-track';
import type {
    Bucket,
    Pair,
    PerformanceTrackData,
} from '@/components/dashboard/performance-track';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as settlementsIndex } from '@/routes/settlements';
import type { PageProps } from '@/types';

/* ════════════════════════ API CONTRACT ════════════════════════ */

export interface AlertItem {
    severity: 'warning' | 'critical';
    kind:
        'stale_in_progress' | 'settlement_difference' | 'low_delivery_product';
    strong: string;
    text: string;
    action: { label: string; href: string };
}

export interface KpiBlock {
    received: { value: number; deltaPct: number | null; buckets: Bucket[] };
    inProgress: {
        value: number;
        sharePct: number;
        deltaPct: number | null;
        agentInitials: string[];
        agentCount: number;
    };
    confirmed: {
        value: number;
        ratePct: number;
        deltaPct: number | null;
        of: number;
    };
    delivered: {
        value: number;
        ratePct: number;
        deltaPct: number | null;
        trend: Bucket[];
    };
    returned: {
        value: number;
        ratePct: number;
        deltaPct: number | null;
        buckets: Bucket[];
    };
}

export interface BreakdownRow {
    name: string;
    img?: string | null;
    orders: number;
    conf: Pair | null;
    deliv: Pair;
    ret: Pair;
}

export interface DashboardProps {
    filters: DashboardFilterValues;
    stores: SimpleOption[];
    agents: SimpleOption[];
    alerts: AlertItem[];
    kpis: KpiBlock;
    income: {
        /** Bucket size, picked by the server from the period length. */
        grain: 'day' | 'week' | 'month';
        totalMad: number;
        buckets: { label: string; amountMad: number; ordersSettled: number }[];
    };
    expected: {
        toReceiveMad: number;
        deliveredUnpaid: number;
        lastSettlement: {
            status: 'matched' | 'difference';
            /** The latest shortfall (negative): the courier paid less. */
            differenceMad: number;
        } | null;
    };
    parcels: {
        total: number;
        stages: {
            key: string;
            label: string;
            count: number;
            ratePct: number;
        }[];
    };
    performance: PerformanceTrackData;
    breakdown: {
        stores: BreakdownRow[];
        products: BreakdownRow[];
        couriers: BreakdownRow[];
    };
}

const FILTER_KEYS: (keyof DashboardFilterValues)[] = [
    'store_ids',
    'period',
    'date_from',
    'date_to',
    'agent_id',
];

/* ════════════════════════ DESIGN TOKENS ════════════════════════ */
// Semantic colours per the handoff — never repainted.

/* ════════════════════════ SMALL PIECES ════════════════════════ */
function DeltaBadge({
    pct,
    goodWhenDown = false,
}: {
    pct: number | null;
    goodWhenDown?: boolean;
}) {
    // No previous window to compare against: no claim.
    if (pct === null) {
        return null;
    }

    const down = pct < 0;
    const good = goodWhenDown ? down : !down;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                good
                    ? 'bg-[#16A08E]/10 text-[#0C7D6F]'
                    : 'bg-[#D92D20]/10 text-[#B3281D]',
            )}
        >
            {down ? '▼' : '▲'} {Math.abs(pct)}%
        </span>
    );
}

function PillBars({
    buckets,
    color,
    unit,
    onTip,
    hideTip,
}: {
    buckets: Bucket[];
    color: string;
    unit: string;
    onTip: (e: MouseEvent, v: string, d: string) => void;
    hideTip: () => void;
}) {
    const max = Math.max(...buckets.map((b) => b.value), 1);

    return (
        <div className="mt-auto flex h-12 items-end gap-1.5 pt-4">
            {buckets.map((b, i) => (
                <span
                    key={i}
                    className="relative h-full w-2 cursor-default overflow-hidden rounded-full bg-muted transition-opacity hover:opacity-80"
                    onMouseMove={(e) =>
                        onTip(e, `${n(b.value)} ${unit}`, b.label)
                    }
                    onMouseLeave={hideTip}
                >
                    <i
                        className="absolute bottom-0 w-full rounded-full"
                        style={{
                            height: `${(b.value / max) * 100}%`,
                            background: color,
                        }}
                    />
                </span>
            ))}
        </div>
    );
}

/* ════════════════════════ SECTIONS ════════════════════════ */

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

function AlertsStrip({ alerts }: { alerts: AlertItem[] }) {
    if (!alerts.length) {
        return null;
    }

    const icon = (a: AlertItem) =>
        a.kind === 'stale_in_progress' ? (
            <Clock className="size-4 shrink-0" style={{ color: C.amberText }} />
        ) : a.kind === 'settlement_difference' ? (
            <TriangleAlert
                className="size-4 shrink-0"
                style={{ color: C.redText }}
            />
        ) : (
            <TrendingDown
                className="size-4 shrink-0"
                style={{ color: C.redText }}
            />
        );

    const onAction = (a: AlertItem) => {
        if (a.kind === 'low_delivery_product') {
            // In-page jump: Products tab, worst delivery first.
            window.dispatchEvent(new CustomEvent('ef:inspect-products'));
        } else {
            router.visit(a.action.href);
        }
    };

    return (
        <section className="grid gap-3 lg:grid-cols-3" data-slot="alerts">
            {alerts.map((a, i) => (
                <div
                    key={i}
                    className={cn(
                        'flex items-center gap-3 rounded-lg border px-4 py-3',
                        a.severity === 'warning'
                            ? 'border-[#EFA22C]/30 bg-[#EFA22C]/8'
                            : 'border-[#D92D20]/30 bg-[#D92D20]/8',
                    )}
                >
                    {icon(a)}
                    <p className="min-w-0 flex-1 truncate text-sm">
                        <b className="font-semibold">{a.strong}</b>{' '}
                        <span className="text-muted-foreground">{a.text}</span>
                    </p>
                    <button
                        type="button"
                        onClick={() => onAction(a)}
                        className={cn(
                            'shrink-0 text-sm font-semibold hover:underline',
                            a.severity === 'warning'
                                ? 'text-[#A87110]'
                                : 'text-[#B3281D]',
                        )}
                    >
                        {a.action.label}
                    </button>
                </div>
            ))}
        </section>
    );
}

function KpiRow({ kpis }: { kpis: KpiBlock }) {
    const { t } = useTranslation();
    const { move, hide, node } = useChartTip();

    const trend = kpis.delivered.trend;
    const maxTrend = Math.max(...trend.map((b) => b.value), 1);
    const px = (i: number) =>
        trend.length > 1 ? 2 + (i * 112) / (trend.length - 1) : 58;
    const py = (v: number) => 30 - (v / maxTrend) * 24;
    const linePts = trend.map((b, i) => `${px(i)},${py(b.value)}`).join(' ');
    const last = trend.at(-1);

    return (
        <section
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
            data-slot="stats.kpis"
        >
            {node}
            {/* 1 · Orders received */}
            <Card className="p-5">
                <CardContent className="flex h-full flex-col p-0">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: C.blue }}
                            />
                            <span className="text-sm font-medium text-muted-foreground">
                                {t('Orders received')}
                            </span>
                        </div>
                        <DeltaBadge pct={kpis.received.deltaPct} />
                    </div>
                    <div className="mt-3 text-[26px] leading-none font-semibold tabular-nums">
                        {n(kpis.received.value)}
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {t('leads in selected period')}
                    </p>
                    <PillBars
                        buckets={kpis.received.buckets}
                        color={C.blue}
                        unit={t('orders')}
                        onTip={move}
                        hideTip={hide}
                    />
                </CardContent>
            </Card>

            {/* 2 · In progress */}
            <Card className="p-5">
                <CardContent className="flex h-full flex-col p-0">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: C.amber }}
                            />
                            <span className="text-sm font-medium text-muted-foreground">
                                {t('In progress')}
                            </span>
                        </div>
                        <DeltaBadge
                            pct={kpis.inProgress.deltaPct}
                            goodWhenDown
                        />
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <div className="text-[26px] leading-none font-semibold tabular-nums">
                            {n(kpis.inProgress.value)}
                        </div>
                        <span
                            className="text-sm font-semibold tabular-nums"
                            style={{ color: C.amberText }}
                        >
                            {kpis.inProgress.sharePct}%
                        </span>
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {t('assigned to agents · no status yet')}
                    </p>
                    <div className="mt-auto flex items-center gap-2.5 pt-4">
                        <div className="flex -space-x-2">
                            {kpis.inProgress.agentInitials
                                .slice(0, 4)
                                .map((ini, i) => (
                                    <span
                                        key={`${ini}-${i}`}
                                        className="flex size-7 items-center justify-center rounded-full bg-[#EFA22C]/15 text-[10px] font-semibold text-[#A87110] ring-2 ring-card"
                                    >
                                        {ini}
                                    </span>
                                ))}
                            {kpis.inProgress.agentCount > 4 && (
                                <span className="flex size-7 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground ring-2 ring-card">
                                    +{kpis.inProgress.agentCount - 4}
                                </span>
                            )}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {t(':count agents on it', {
                                count: kpis.inProgress.agentCount,
                            })}
                        </span>
                    </div>
                </CardContent>
            </Card>

            {/* 3 · Confirmed */}
            <Card className="p-5">
                <CardContent className="flex h-full flex-col p-0">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: C.purple }}
                            />
                            <span className="text-sm font-medium text-muted-foreground">
                                {t('Confirmed')}
                            </span>
                        </div>
                        <DeltaBadge pct={kpis.confirmed.deltaPct} />
                    </div>
                    <div className="mt-auto flex items-end justify-between gap-3 pt-3">
                        <div>
                            <div className="text-[26px] leading-none font-semibold tabular-nums">
                                {n(kpis.confirmed.value)}
                            </div>
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                {t('of :count orders', {
                                    count: n(kpis.confirmed.of),
                                })}
                            </p>
                        </div>
                        <div className="relative size-16 shrink-0">
                            <Ring
                                pct={kpis.confirmed.ratePct}
                                color={C.purple}
                                size={64}
                                stroke={7}
                            />
                            <span className="absolute inset-0 flex items-center justify-center text-[11px] font-semibold tabular-nums">
                                {kpis.confirmed.ratePct}%
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* 4 · Delivered */}
            <Card className="p-5">
                <CardContent className="flex h-full flex-col p-0">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: C.green }}
                            />
                            <span className="text-sm font-medium text-muted-foreground">
                                {t('Delivered')}
                            </span>
                        </div>
                        <DeltaBadge pct={kpis.delivered.deltaPct} />
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <div className="text-[26px] leading-none font-semibold tabular-nums">
                            {n(kpis.delivered.value)}
                        </div>
                        <span
                            className="text-sm font-semibold tabular-nums"
                            style={{ color: C.greenText }}
                        >
                            {kpis.delivered.ratePct}%
                        </span>
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {t('of confirmed')}
                    </p>
                    <div className="mt-auto h-12 pt-4">
                        <svg
                            viewBox="0 0 120 32"
                            className="h-8 w-full overflow-visible"
                        >
                            <polyline
                                points={linePts}
                                fill="none"
                                stroke={C.green}
                                strokeWidth={2}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                            {last && (
                                <circle
                                    cx={px(trend.length - 1)}
                                    cy={py(last.value)}
                                    r={3}
                                    fill={C.green}
                                    stroke="var(--card)"
                                    strokeWidth={2}
                                />
                            )}
                            {trend.map((b, i) => (
                                <circle
                                    key={i}
                                    cx={px(i)}
                                    cy={py(b.value)}
                                    r={9}
                                    fill="transparent"
                                    className="cursor-default"
                                    onMouseMove={(e) =>
                                        move(
                                            e,
                                            `${n(b.value)} ${t('delivered')}`,
                                            b.label,
                                        )
                                    }
                                    onMouseLeave={hide}
                                />
                            ))}
                        </svg>
                    </div>
                </CardContent>
            </Card>

            {/* 5 · Returned */}
            <Card className="p-5">
                <CardContent className="flex h-full flex-col p-0">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: C.red }}
                            />
                            <span className="text-sm font-medium text-muted-foreground">
                                {t('Returned')}
                            </span>
                        </div>
                        <DeltaBadge pct={kpis.returned.deltaPct} goodWhenDown />
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <div className="text-[26px] leading-none font-semibold tabular-nums">
                            {n(kpis.returned.value)}
                        </div>
                        <span
                            className="text-sm font-semibold tabular-nums"
                            style={{ color: C.redText }}
                        >
                            {kpis.returned.ratePct}%
                        </span>
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {t('of confirmed')}
                    </p>
                    <PillBars
                        buckets={kpis.returned.buckets}
                        color={C.red}
                        unit={t('returns')}
                        onTip={move}
                        hideTip={hide}
                    />
                </CardContent>
            </Card>
        </section>
    );
}

/** A "nice" axis ceiling (1 / 2 / 5 × 10ⁿ) at or above the max. */
/** MAD with centimes, e.g. 1,500.00. */
function money2(value: number): string {
    return value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function niceCeiling(max: number): number {
    if (max <= 0) {
        return 1000;
    }

    const magnitude = 10 ** Math.floor(Math.log10(max));
    const scaled = max / magnitude;
    const step = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;

    return step * magnitude;
}

function FinanceRow({
    income,
    expected,
    parcels,
}: Pick<DashboardProps, 'income' | 'expected' | 'parcels'>) {
    const { t } = useTranslation();
    const { move, hide, node } = useChartTip();

    /* income area chart geometry; y-scale follows the data */
    const X0 = 46;
    const X1 = 586;
    const Y1 = 224;
    const Y0 = 16;
    const months = income.buckets;
    // At most ~8 x-axis labels, so a 30-day window stays readable.
    const labelEvery = Math.max(1, Math.ceil(months.length / 8));
    const top = niceCeiling(Math.max(...months.map((m) => m.amountMad), 0));
    const X = (i: number) =>
        months.length > 1
            ? X0 + (i * (X1 - X0)) / (months.length - 1)
            : (X0 + X1) / 2;
    const Y = (v: number) => Y1 - (v / top) * (Y1 - Y0);
    const pts = months.map((m, i) => [X(i), Y(m.amountMad)] as const);
    const line = pts.map((p) => p.join(',')).join(' ');
    const first = pts[0];
    const lastPt = pts.at(-1);
    const area =
        first && lastPt
            ? `M${first[0]},${Y1} ` +
              pts.map((p) => `L${p[0]},${p[1]}`).join(' ') +
              ` L${lastPt[0]},${Y1} Z`
            : '';
    const ticks = [0.25, 0.5, 0.75, 1].map((f) => f * top);
    const tickLabel = (v: number) =>
        v >= 1000 ? `${Math.round(v / 1000)}K` : String(Math.round(v));
    const settled = expected.lastSettlement;
    const hasIncome = months.some((m) => m.amountMad > 0);

    return (
        <section
            className="grid gap-4 lg:grid-cols-3"
            data-slot="finance.overview"
        >
            {node}
            <Card className="grid overflow-hidden p-0 lg:col-span-2 lg:grid-cols-[1.2fr_1fr]">
                {/* INCOME */}
                <div className="p-6" data-slot="finance.income">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {t('Income')}
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {t('Collected from delivered orders')}
                    </p>
                    <p className="mt-3">
                        <span className="font-mono text-2xl font-semibold tabular-nums">
                            {money(income.totalMad)}
                        </span>{' '}
                        <span className="text-xs text-muted-foreground">
                            MAD ·{' '}
                            {income.grain === 'day'
                                ? t('per day')
                                : income.grain === 'week'
                                  ? t('per week')
                                  : t('per month')}
                        </span>
                    </p>
                    <div className="mt-6">
                        {hasIncome ? (
                            <svg
                                viewBox="0 0 600 258"
                                className="h-64 w-full overflow-visible"
                            >
                                <defs>
                                    <linearGradient
                                        id="incomeGrad"
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor={C.green}
                                            stopOpacity={0.28}
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor={C.green}
                                            stopOpacity={0}
                                        />
                                    </linearGradient>
                                </defs>
                                {ticks.map((g) => (
                                    <g key={g}>
                                        <line
                                            x1={X0}
                                            y1={Y(g)}
                                            x2={X1}
                                            y2={Y(g)}
                                            stroke="var(--border)"
                                            strokeWidth={1.5}
                                            strokeDasharray="1.5 6"
                                            strokeLinecap="round"
                                        />
                                        <text
                                            x={X0 - 10}
                                            y={Y(g) + 4}
                                            textAnchor="end"
                                            fontSize={11}
                                            fill="var(--muted-foreground)"
                                        >
                                            {tickLabel(g)}
                                        </text>
                                    </g>
                                ))}
                                <path d={area} fill="url(#incomeGrad)" />
                                <polyline
                                    points={line}
                                    fill="none"
                                    stroke={C.green}
                                    strokeWidth={2.5}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                />
                                {lastPt && (
                                    <circle
                                        cx={lastPt[0]}
                                        cy={lastPt[1]}
                                        r={4}
                                        fill={C.green}
                                        stroke="var(--card)"
                                        strokeWidth={2}
                                    />
                                )}
                                {months.map((m, i) => (
                                    <g key={`${m.label}-${i}`}>
                                        {(i % labelEvery === 0 ||
                                            i === months.length - 1) && (
                                            <text
                                                x={X(i)}
                                                y={250}
                                                textAnchor="middle"
                                                fontSize={11}
                                                fill="var(--muted-foreground)"
                                            >
                                                {t(m.label)}
                                            </text>
                                        )}
                                        <circle
                                            cx={X(i)}
                                            cy={Y(m.amountMad)}
                                            r={13}
                                            fill="transparent"
                                            className="cursor-default"
                                            onMouseMove={(e) =>
                                                move(
                                                    e,
                                                    `${money(m.amountMad)} MAD`,
                                                    `${t(m.label)} · ${t(':count orders delivered', { count: n(m.ordersSettled) })}`,
                                                )
                                            }
                                            onMouseLeave={hide}
                                        />
                                    </g>
                                ))}
                            </svg>
                        ) : (
                            <EmptyState title={t('No income to show')} />
                        )}
                    </div>
                </div>
                {/* EXPECTED */}
                <div
                    className="flex flex-col border-t border-border p-6 lg:border-t-0 lg:border-l"
                    data-slot="finance.expected"
                >
                    <h2 className="text-lg font-semibold tracking-tight">
                        {t('Expected')}
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {t('Cash with your couriers · not settled yet')}
                    </p>
                    <div className="my-auto grid grid-cols-2 py-8">
                        <div className="flex flex-col items-center gap-3 text-center">
                            <span className="flex size-12 items-center justify-center rounded-xl bg-[#EFA22C]/12">
                                <CircleDollarSign
                                    className="size-5"
                                    style={{ color: C.amberText }}
                                />
                            </span>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    {t('To receive')}
                                </p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums">
                                    {money(expected.toReceiveMad)}{' '}
                                    <span className="text-sm font-medium text-muted-foreground">
                                        MAD
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col items-center gap-3 border-l border-border text-center">
                            <span className="flex size-12 items-center justify-center rounded-xl bg-[#16A08E]/12">
                                <Wallet
                                    className="size-5"
                                    style={{ color: C.greenText }}
                                />
                            </span>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    {t('Delivered, unpaid')}
                                </p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums">
                                    {n(expected.deliveredUnpaid)}{' '}
                                    <span className="text-sm font-medium text-muted-foreground">
                                        {t('orders')}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="mt-auto flex items-center justify-between gap-4 border-t border-border pt-5">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {t('Last settlement')}
                            </p>
                            {settled === null ? (
                                <p className="mt-0.5 text-sm font-semibold text-muted-foreground">
                                    {t('None yet')}
                                </p>
                            ) : (
                                <>
                                    {settled.status === 'matched' ? (
                                        <p
                                            className="mt-0.5 inline-flex items-center gap-1.5 text-sm font-semibold"
                                            style={{ color: C.greenText }}
                                        >
                                            <CheckCircle2 className="size-4" />
                                            {t('All good · matched')}
                                        </p>
                                    ) : (
                                        <p
                                            className="mt-0.5 inline-flex items-center gap-1.5 font-mono text-sm font-semibold tabular-nums"
                                            style={{ color: C.redText }}
                                        >
                                            <TriangleAlert className="size-4" />
                                            −
                                            {money2(
                                                Math.abs(settled.differenceMad),
                                            )}{' '}
                                            MAD
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                        <Button asChild>
                            <a href={settlementsIndex().url}>
                                {t('View settlements')}
                            </a>
                        </Button>
                    </div>
                </div>
            </Card>

            {/* PARCELS */}
            <Card className="p-6">
                <CardContent
                    className="flex h-full flex-col p-0"
                    data-slot="parcels.pipeline"
                >
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-lg bg-secondary">
                            <Package className="size-[18px] text-primary" />
                        </span>
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                {t('Parcels')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('Shipment pipeline')}
                            </p>
                        </div>
                    </div>
                    <div className="mt-4 border-t border-border pt-4">
                        <span className="text-2xl font-semibold tabular-nums">
                            {n(parcels.total)}
                        </span>
                        <span className="ml-1.5 text-sm text-muted-foreground">
                            {t('parcels this period')}
                        </span>
                    </div>
                    <div className="mt-5 flex flex-1 flex-col justify-between gap-5">
                        {parcels.stages.map((s) => {
                            const col =
                                {
                                    ready_to_ship: C.amber,
                                    shipped: C.petrol,
                                    delivered: C.green,
                                    returned: C.red,
                                }[s.key] ?? C.gray;

                            return (
                                <div key={s.key} data-slot={`parcels.${s.key}`}>
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="text-sm font-medium">
                                            {s.label}
                                        </span>
                                        <span className="text-sm font-semibold tabular-nums">
                                            {n(s.count)}{' '}
                                            <span className="ml-1 font-normal text-muted-foreground">
                                                {s.ratePct}%
                                            </span>
                                        </span>
                                    </div>
                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full"
                                            style={{
                                                width: `${s.ratePct}%`,
                                                background: col,
                                            }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}

/* ---------- breakdown ---------- */
type BdTab = 'stores' | 'products' | 'couriers';
type SortKey = 'name' | 'orders' | 'conf' | 'deliv' | 'ret';

function Metric({ cell, color }: { cell: Pair | null; color: string }) {
    if (!cell) {
        return <span className="text-muted-foreground">—</span>;
    }

    const [count, rate] = cell;

    return (
        <div className="max-w-36">
            <p className="tabular-nums">
                <span className="font-semibold">{n(count)}</span>{' '}
                <span className="text-xs text-muted-foreground">· {rate}%</span>
            </p>
            <div className="mt-1.5 h-1.5 w-full rounded-full bg-muted">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(rate, 100)}%`,
                        background: color,
                    }}
                />
            </div>
        </div>
    );
}

function EmptyState({ title }: { title: string }) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-56 flex-col items-center justify-center p-8 text-center">
            <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <MessageCircle className="size-5" />
            </span>
            <p className="mt-4 text-sm font-semibold">{title}</p>
            <p className="mt-1 max-w-64 text-xs text-muted-foreground">
                {t(
                    'No data for this selection. Try a wider period or different stores.',
                )}
            </p>
        </div>
    );
}

const sortVal = (r: BreakdownRow, k: SortKey): string | number =>
    k === 'name'
        ? r.name.toLowerCase()
        : k === 'orders'
          ? r.orders
          : k === 'conf'
            ? r.conf
                ? r.conf[0]
                : -1
            : k === 'deliv'
              ? r.deliv[0]
              : r.ret[0];

export function BreakdownSection({
    breakdown,
}: Pick<DashboardProps, 'breakdown'>) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<BdTab>('stores');
    const [sort, setSort] = useState<{ key: SortKey; dir: 1 | -1 }>({
        key: 'orders',
        dir: -1,
    });
    const secRef = useRef<HTMLElement>(null);
    const [flash, setFlash] = useState(false);

    /* alert "Inspect" jump */
    useEffect(() => {
        const handler = () => {
            setTab('products');
            setSort({ key: 'deliv', dir: 1 });
            secRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
            setFlash(true);
            const timer = window.setTimeout(() => setFlash(false), 1800);

            return () => window.clearTimeout(timer);
        };

        window.addEventListener('ef:inspect-products', handler);

        return () => window.removeEventListener('ef:inspect-products', handler);
    }, []);

    const isC = tab === 'couriers';
    const rows = useMemo(
        () =>
            [...breakdown[tab]].sort((a, b) => {
                const va = sortVal(a, sort.key);
                const vb = sortVal(b, sort.key);

                return (va < vb ? -1 : va > vb ? 1 : 0) * sort.dir;
            }),
        [breakdown, tab, sort],
    );
    const cols: { key: SortKey; label: string; cls: string }[] = [
        { key: 'name', label: t('Name'), cls: 'px-6 text-left' },
        {
            key: 'orders',
            label: isC ? t('Parcels') : t('Orders'),
            cls: 'w-28 px-4 text-left',
        },
        ...(isC
            ? []
            : [
                  {
                      key: 'conf' as SortKey,
                      label: t('Confirmed'),
                      cls: 'w-44 px-4 text-left',
                  },
              ]),
        { key: 'deliv', label: t('Delivered'), cls: 'w-44 px-4 text-left' },
        { key: 'ret', label: t('Returned'), cls: 'w-44 px-4 text-left' },
    ];
    const clickSort = (k: SortKey) =>
        setSort((s) =>
            s.key === k
                ? { key: k, dir: (s.dir * -1) as 1 | -1 }
                : { key: k, dir: k === 'name' ? 1 : -1 },
        );

    const tabLabels: Record<BdTab, string> = {
        stores: t('Stores'),
        products: t('Products'),
        couriers: t('Couriers'),
    };

    return (
        <section ref={secRef} id="breakdown" data-slot="breakdown">
            <Card
                className={cn(
                    'p-0 transition-shadow',
                    flash && 'ring-2 ring-[#D92D20]/40',
                )}
            >
                <div className="flex flex-wrap items-end justify-between gap-4 p-6 pb-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {t('Breakdown')}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t('How each store, product and courier is doing')}
                        </p>
                    </div>
                    <div
                        className="flex rounded-full bg-muted p-1"
                        role="tablist"
                    >
                        {(['stores', 'products', 'couriers'] as BdTab[]).map(
                            (tb) => (
                                <button
                                    key={tb}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === tb}
                                    onClick={() => setTab(tb)}
                                    className={cn(
                                        'rounded-full px-4 py-1.5 text-sm font-medium',
                                        tab === tb
                                            ? 'bg-card text-foreground shadow-xs'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {tabLabels[tb]}
                                </button>
                            ),
                        )}
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-border text-muted-foreground">
                                {cols.map((c) => (
                                    <th
                                        key={c.key}
                                        className={cn(
                                            'h-11 font-medium',
                                            c.cls,
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => clickSort(c.key)}
                                            className={cn(
                                                'inline-flex items-center gap-1 hover:text-foreground',
                                                sort.key === c.key &&
                                                    'text-foreground',
                                            )}
                                        >
                                            {c.label}
                                            <span
                                                className={cn(
                                                    'text-[11px]',
                                                    sort.key !== c.key &&
                                                        'opacity-40',
                                                )}
                                            >
                                                {sort.key === c.key
                                                    ? sort.dir < 0
                                                        ? '↓'
                                                        : '↑'
                                                    : '↕'}
                                            </span>
                                        </button>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr
                                    key={r.name}
                                    className="border-b border-border last:border-0 hover:bg-muted/40"
                                >
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-3">
                                            {r.img ? (
                                                <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-white">
                                                    <img
                                                        src={r.img}
                                                        className="size-6 object-contain"
                                                        alt=""
                                                    />
                                                </span>
                                            ) : (
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    {tab === 'stores' ? (
                                                        <Store className="size-4" />
                                                    ) : (
                                                        <Box className="size-4" />
                                                    )}
                                                </span>
                                            )}
                                            <span className="font-semibold">
                                                {r.name}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-4 font-semibold tabular-nums">
                                        {n(r.orders)}
                                    </td>
                                    {!isC && (
                                        <td className="px-4 py-4">
                                            <Metric
                                                cell={r.conf}
                                                color={C.purple}
                                            />
                                        </td>
                                    )}
                                    <td className="px-4 py-4">
                                        <Metric
                                            cell={r.deliv}
                                            color={C.green}
                                        />
                                    </td>
                                    <td className="px-4 py-4">
                                        <Metric cell={r.ret} color={C.red} />
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={cols.length}>
                                        <EmptyState
                                            title={t('Nothing to break down')}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>
    );
}

/* ════════════════════════ PAGE ════════════════════════ */
export default function AdminDashboard(props: DashboardProps) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(dashboard().url, props.filters, FILTER_KEYS);

    const firstName = auth.user.name.split(' ')[0];

    return (
        <>
            <Head title={t('Dashboard')} />

            <div className="space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {greeting(t)}, {firstName}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t("Here's how the business is doing.")}
                        </p>
                    </div>

                    <DashboardFilters
                        stores={props.stores}
                        draft={draft}
                        onChange={updateFilters}
                        onReset={resetFilters}
                        hasActiveFilters={hasActiveFilters}
                    />
                </div>

                <AlertsStrip alerts={props.alerts} />
                <KpiRow kpis={props.kpis} />
                <FinanceRow
                    income={props.income}
                    expected={props.expected}
                    parcels={props.parcels}
                />
                <PerformanceTrack
                    performance={props.performance}
                    agents={props.agents}
                    agentId={draft.agent_id}
                    onAgentChange={(agentId) =>
                        updateFilters({ agent_id: agentId })
                    }
                />
                <BreakdownSection breakdown={props.breakdown} />
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
