import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    DEFAULT_PERIOD,
    PeriodFilter,
} from '@/components/dashboard/dashboard-filters';
import type { DashboardFilterValues } from '@/components/dashboard/dashboard-filters';
import { useChartTip } from '@/components/dashboard/performance-track';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { dashboard } from '@/routes/super-admin';
import type { PageProps } from '@/types';

/* ────────────────────────── props contract ──────────────────────── */
type Pair = [count: number, ratePct: number];
type Bucket = { label: string; value: number };

type BusinessRow = {
    id: number;
    name: string;
    initials: string;
    orders: number;
    inProgress: Pair;
    confirmed: Pair;
    delivered: Pair;
    returned: Pair;
};
type CourierRef = { name: string; logoUrl: string };
type ParcelsBusinessRow = {
    id: number;
    name: string;
    initials: string;
    parcels: number;
    couriers: CourierRef[];
    shipped: Pair;
    delivered: Pair;
    returned: Pair;
};
type ParcelsCourierRow = {
    name: string;
    logoUrl: string;
    businesses: string[];
    parcels: number;
    shipped: Pair;
    delivered: Pair;
    returned: Pair;
};
type ConfirmerRow = {
    id: number;
    name: string;
    initials: string;
    business: string;
    orders: number;
    confirmed: Pair;
    delivered: Pair;
    commissionsMad: number;
};
type FulfilmentRow = {
    id: number;
    name: string;
    initials: string;
    business: string;
    parcels: number;
    commissionsMad: number;
};

type FilterValues = Pick<
    DashboardFilterValues,
    'period' | 'date_from' | 'date_to'
> & {
    business?: string;
};

type Props = {
    filters: FilterValues & {
        businesses: { id: number; name: string }[];
        rangeLabel: string;
    };
    trend: { totalOrders: number; buckets: Bucket[] };
    businesses: {
        totals: {
            inProgress: number;
            confirmedPending: number;
            delivered: number;
            returned: number;
        };
        rows: BusinessRow[];
    };
    parcels: {
        total: number;
        byBusiness: ParcelsBusinessRow[];
        byCourier: ParcelsCourierRow[];
    };
    team: { confirmers: ConfirmerRow[]; fulfilment: FulfilmentRow[] };
};

/* ────────────────────────── design tokens ────────────────────────── */
const C = {
    teal: '#16A08E',
    orange: '#F2602F',
    mustard: '#EFA22C',
    petrol: '#468FA5',
    red: '#D92D20',
};

const ALL = '__all__';
const FILTER_KEYS: (keyof FilterValues)[] = [
    'business',
    'period',
    'date_from',
    'date_to',
];

/* ────────────────────────── shared bits ──────────────────────────── */
function Th({ children }: { children: React.ReactNode }) {
    return (
        <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground first:pl-6 last:pr-6">
            {children}
        </th>
    );
}

function MetricCell({ pair, color }: { pair: Pair; color: string }) {
    return (
        <td className="px-4 py-4">
            <span className="font-semibold tabular-nums">
                {pair[0].toLocaleString()}
            </span>{' '}
            <span className="text-xs text-muted-foreground">· {pair[1]}%</span>
            <div className="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(100, pair[1])}%`,
                        background: color,
                    }}
                />
            </div>
        </td>
    );
}

function Who({
    initials,
    name,
    sub,
    square,
}: {
    initials: string;
    name: string;
    sub: string;
    square?: boolean;
}) {
    return (
        <div className="flex items-center gap-3">
            <span
                className={`flex size-9 shrink-0 items-center justify-center ${square ? 'rounded-lg' : 'rounded-full'} bg-secondary text-xs font-semibold text-secondary-foreground`}
            >
                {initials}
            </span>
            <div className="min-w-0">
                <p className="truncate font-semibold">{name}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
        </div>
    );
}

function CourierLogo({ c, large }: { c: CourierRef; large?: boolean }) {
    return (
        <span
            title={c.name}
            className={`inline-flex ${large ? 'size-9' : 'size-7'} items-center justify-center overflow-hidden rounded-full border border-border bg-white ring-2 ring-card`}
        >
            <img
                src={c.logoUrl}
                alt={c.name}
                className={`${large ? 'size-7' : 'size-5'} object-contain`}
            />
        </span>
    );
}

function Money({ value }: { value: number }) {
    return (
        <td className="px-4 py-4 pr-6 font-mono text-sm font-semibold tabular-nums">
            {value.toLocaleString()}{' '}
            <span className="font-sans text-xs font-normal text-muted-foreground">
                MAD
            </span>
        </td>
    );
}

function EmptyRow({
    colSpan,
    children,
}: {
    colSpan: number;
    children: React.ReactNode;
}) {
    return (
        <tr>
            <td
                colSpan={colSpan}
                className="px-6 py-10 text-center text-sm text-muted-foreground"
            >
                {children}
            </td>
        </tr>
    );
}

function SegmentedTabs<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (next: T) => void;
    options: { value: T; label: string }[];
}) {
    return (
        <Tabs value={value} onValueChange={(next) => onChange(next as T)}>
            <TabsList className="h-auto rounded-lg bg-muted p-1">
                {options.map((option) => (
                    <TabsTrigger
                        key={option.value}
                        value={option.value}
                        className="rounded-md px-4 py-1.5 text-sm font-medium data-[state=active]:bg-card data-[state=active]:text-foreground data-[state=active]:shadow-xs"
                    >
                        {option.label}
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}

const niceStep = (m: number) => {
    const pow = Math.pow(10, Math.floor(Math.log10(Math.max(1, m / 3.2))));

    for (const s of [1, 2, 2.5, 5, 10]) {
        if (s * pow * 4 >= m * 1.02) {
            return s * pow;
        }
    }

    return 10 * pow;
};
const fmtN = (v: number) =>
    v >= 1000
        ? ((v / 1000) % 1 ? (v / 1000).toFixed(1) : v / 1000) + 'K'
        : String(v);

function greeting(t: (key: string) => string): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return t('Good morning');
    }

    if (hour < 18) {
        return t('Good afternoon');
    }

    return t('Good evening');
}

/* ────────────────────────── 1 · Orders trend ─────────────────────── */
function TrendCard({ trend }: Pick<Props, 'trend'>) {
    const { t } = useTranslation();
    const { move, hide, node } = useChartTip();
    const B = trend.buckets;
    const n = B.length;

    const svg = useMemo(() => {
        const mx = Math.max(...B.map((b) => b.value), 1);
        const step = niceStep(mx);
        const W = 1000;
        const X0 = 40;
        const X1 = W - 14;
        const Y1 = 224;
        const Y0 = 16;
        const X = (i: number) =>
            n > 1 ? X0 + (i * (X1 - X0)) / (n - 1) : (X0 + X1) / 2;
        const Y = (v: number) => Y1 - (v / (step * 4)) * (Y1 - Y0);
        const pts = B.map((b, i) => [X(i), Y(b.value)] as const);

        return {
            W,
            X0,
            X1,
            Y1,
            step,
            X,
            Y,
            pts,
            line: pts.map((p) => p.join(',')).join(' '),
            area: pts.length
                ? `M${pts[0][0]},${Y1} ${pts.map((p) => `L${p[0]},${p[1]}`).join(' ')} L${pts[n - 1][0]},${Y1} Z`
                : '',
            skip: Math.max(1, Math.ceil(n / 10)),
            hitR: Math.min(
                13,
                Math.max(5, Math.round((X1 - X0) / Math.max(1, n - 1) / 2)),
            ),
        };
    }, [B, n]);

    return (
        <section data-slot="sa.trend">
            {node}
            <div className="rounded-xl border border-border bg-card p-6 shadow-xs">
                <div className="flex flex-wrap items-center justify-between gap-4 pb-2">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {t('Orders trend')}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t(
                                'All your operation in one line — spot the jumps and the dips',
                            )}
                        </p>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        <span className="text-xl font-semibold text-foreground tabular-nums">
                            {trend.totalOrders.toLocaleString()}
                        </span>{' '}
                        {t('orders')}
                    </p>
                </div>
                {trend.totalOrders === 0 ? (
                    <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">
                        {t(
                            'No data for this selection. Try a wider period or different businesses.',
                        )}
                    </div>
                ) : (
                    <svg
                        viewBox={`0 0 ${svg.W} 258`}
                        className="h-72 w-full overflow-visible"
                        preserveAspectRatio="none"
                    >
                        <defs>
                            <linearGradient
                                id="saTrendGrad"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor={C.teal}
                                    stopOpacity={0.22}
                                />
                                <stop
                                    offset="100%"
                                    stopColor={C.teal}
                                    stopOpacity={0}
                                />
                            </linearGradient>
                        </defs>
                        {[1, 2, 3, 4].map((k) => (
                            <g key={k}>
                                <line
                                    x1={svg.X0}
                                    y1={svg.Y(svg.step * k)}
                                    x2={svg.X1}
                                    y2={svg.Y(svg.step * k)}
                                    stroke="var(--border)"
                                    strokeWidth={1.5}
                                    strokeDasharray="1.5 6"
                                    strokeLinecap="round"
                                />
                                <text
                                    x={svg.X0 - 8}
                                    y={svg.Y(svg.step * k) + 4}
                                    textAnchor="end"
                                    fontSize={11}
                                    fill="var(--muted-foreground)"
                                >
                                    {fmtN(svg.step * k)}
                                </text>
                            </g>
                        ))}
                        <path d={svg.area} fill="url(#saTrendGrad)" />
                        <polyline
                            points={svg.line}
                            fill="none"
                            stroke={C.teal}
                            strokeWidth={2.5}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            vectorEffect="non-scaling-stroke"
                        />
                        <circle
                            cx={svg.pts[n - 1][0]}
                            cy={svg.pts[n - 1][1]}
                            r={4}
                            fill={C.teal}
                            stroke="var(--card)"
                            strokeWidth={2}
                        />
                        {B.map((b, i) => (
                            <g key={i}>
                                {i % svg.skip === 0 && (
                                    <text
                                        x={svg.X(i)}
                                        y={250}
                                        textAnchor="middle"
                                        fontSize={11}
                                        fill="var(--muted-foreground)"
                                    >
                                        {b.label}
                                    </text>
                                )}
                                <circle
                                    cx={svg.pts[i][0]}
                                    cy={svg.pts[i][1]}
                                    r={svg.hitR}
                                    fill="transparent"
                                    className="cursor-default"
                                    onMouseMove={(e) =>
                                        move(
                                            e,
                                            `${b.value.toLocaleString()} ${t('orders')}`,
                                            b.label,
                                        )
                                    }
                                    onMouseLeave={hide}
                                />
                            </g>
                        ))}
                    </svg>
                )}
            </div>
        </section>
    );
}

/* ────────────────── 2 · Businesses: totals bar + table ───────────── */
function BusinessesCard({ businesses }: Pick<Props, 'businesses'>) {
    const { t } = useTranslation();
    const { move, hide, node } = useChartTip();
    const totals = businesses.totals;
    const segs = [
        { l: t('In progress'), v: totals.inProgress, bg: C.mustard },
        { l: t('Confirmed'), v: totals.confirmedPending, bg: C.orange },
        { l: t('Delivered'), v: totals.delivered, bg: C.teal },
        { l: t('Returned'), v: totals.returned, bg: C.red },
    ];
    const base = segs.reduce((s, x) => s + x.v, 0);
    const totalOrders = businesses.rows.reduce((s, r) => s + r.orders, 0);
    const flex = (pct: number) => ({ flex: `${pct} 1 0%`, minWidth: 96 });
    const count = businesses.rows.length;

    return (
        <section data-slot="sa.businesses">
            {node}
            <div className="rounded-xl border border-border bg-card shadow-xs">
                <div className="flex flex-wrap items-center justify-between gap-4 p-6 pb-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {t('Businesses')}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t(
                                'How each business is performing in the selected period',
                            )}
                        </p>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        <span className="text-xl font-semibold text-foreground tabular-nums">
                            {totalOrders.toLocaleString()}
                        </span>{' '}
                        {t('orders')}
                    </p>
                </div>
                <div className="px-6 pb-6">
                    {base === 0 ? (
                        <div className="mt-7 flex h-11 items-center justify-center rounded-xl bg-muted/50 text-sm text-muted-foreground">
                            {t('No orders in this period')}
                        </div>
                    ) : (
                        <>
                            <div className="flex">
                                {segs.map((s) => {
                                    const pct = +((s.v / base) * 100).toFixed(
                                        1,
                                    );

                                    return (
                                        <div
                                            key={s.l}
                                            className="min-w-0"
                                            style={flex(pct)}
                                        >
                                            <p className="truncate text-sm text-muted-foreground">
                                                {s.l}
                                            </p>
                                            <div className="mt-1 mb-2 h-2 w-0.5 rounded bg-border" />
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="flex h-11 overflow-hidden rounded-xl">
                                {segs.map((s) => {
                                    const pct = +((s.v / base) * 100).toFixed(
                                        1,
                                    );

                                    return (
                                        <div
                                            key={s.l}
                                            className="flex min-w-0 cursor-default items-center"
                                            style={{
                                                ...flex(pct),
                                                background: s.bg,
                                            }}
                                            onMouseMove={(e) =>
                                                move(
                                                    e,
                                                    `${s.v.toLocaleString()} ${t('orders')} · ${pct}%`,
                                                    s.l,
                                                )
                                            }
                                            onMouseLeave={hide}
                                        >
                                            <span className="truncate px-3 text-sm font-semibold text-white tabular-nums">
                                                {pct}%
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </div>
                <div className="max-h-[352px] overflow-x-auto overflow-y-auto border-t border-border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 z-10 bg-card">
                            <tr className="border-b border-border">
                                <Th>{t('Business')}</Th>
                                <Th>{t('Orders')}</Th>
                                <Th>{t('In progress')}</Th>
                                <Th>{t('Confirmation')}</Th>
                                <Th>{t('Delivery')}</Th>
                                <Th>{t('Returned')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {businesses.rows.map((r) => (
                                <tr
                                    key={r.id}
                                    className="border-b border-border last:border-0 hover:bg-muted/40"
                                >
                                    <td className="px-4 py-4 first:pl-6">
                                        <Who
                                            square
                                            initials={r.initials}
                                            name={r.name}
                                            sub={`${r.orders.toLocaleString()} ${t('orders')}`}
                                        />
                                    </td>
                                    <td className="px-4 py-4 font-semibold tabular-nums">
                                        {r.orders.toLocaleString()}
                                    </td>
                                    <MetricCell
                                        pair={r.inProgress}
                                        color={C.mustard}
                                    />
                                    <MetricCell
                                        pair={r.confirmed}
                                        color={C.orange}
                                    />
                                    <MetricCell
                                        pair={r.delivered}
                                        color={C.teal}
                                    />
                                    <MetricCell
                                        pair={r.returned}
                                        color={C.red}
                                    />
                                </tr>
                            ))}
                            {!count && (
                                <EmptyRow colSpan={6}>
                                    {t(
                                        'No data for this selection. Try a wider period or different businesses.',
                                    )}
                                </EmptyRow>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="border-t border-border px-6 py-3 text-xs text-muted-foreground">
                    {t(count === 1 ? ':count business' : ':count businesses', {
                        count,
                    })}{' '}
                    · {t('scroll to see all')}
                </div>
            </div>
        </section>
    );
}

/* ─────────────── 3 · Parcels: by business / by courier ───────────── */
function ParcelsCard({ parcels }: Pick<Props, 'parcels'>) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<'business' | 'courier'>('business');
    const count =
        tab === 'business'
            ? parcels.byBusiness.length
            : parcels.byCourier.length;

    return (
        <section data-slot="sa.parcels">
            <div className="rounded-xl border border-border bg-card shadow-xs">
                <div className="flex flex-wrap items-center justify-between gap-4 p-6 pb-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {t('Parcels')}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t(
                                'Shipment pipeline per business · with their delivery couriers',
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <p className="text-sm text-muted-foreground">
                            <span className="text-xl font-semibold text-foreground tabular-nums">
                                {parcels.total.toLocaleString()}
                            </span>{' '}
                            {t('parcels')}
                        </p>
                        <SegmentedTabs
                            value={tab}
                            onChange={setTab}
                            options={[
                                { value: 'business', label: t('By business') },
                                { value: 'courier', label: t('By courier') },
                            ]}
                        />
                    </div>
                </div>
                <div className="max-h-[352px] overflow-x-auto overflow-y-auto border-t border-border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 z-10 bg-card">
                            <tr className="border-b border-border">
                                <Th>
                                    {tab === 'business'
                                        ? t('Business')
                                        : t('Courier')}
                                </Th>
                                <Th>
                                    {tab === 'business'
                                        ? t('Couriers')
                                        : t('Parcels')}
                                </Th>
                                <Th>{t('Shipped')}</Th>
                                <Th>{t('Delivered')}</Th>
                                <Th>{t('Returned')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {tab === 'business'
                                ? parcels.byBusiness.map((r) => (
                                      <tr
                                          key={r.id}
                                          className="border-b border-border last:border-0 hover:bg-muted/40"
                                      >
                                          <td className="px-4 py-4 first:pl-6">
                                              <Who
                                                  square
                                                  initials={r.initials}
                                                  name={r.name}
                                                  sub={`${r.parcels.toLocaleString()} ${t('parcels')}`}
                                              />
                                          </td>
                                          <td className="px-4 py-4">
                                              <div className="flex -space-x-1.5">
                                                  {r.couriers.map((c) => (
                                                      <CourierLogo
                                                          key={c.name}
                                                          c={c}
                                                      />
                                                  ))}
                                                  {!r.couriers.length && (
                                                      <span className="text-xs text-muted-foreground">
                                                          —
                                                      </span>
                                                  )}
                                              </div>
                                          </td>
                                          <MetricCell
                                              pair={r.shipped}
                                              color={C.petrol}
                                          />
                                          <MetricCell
                                              pair={r.delivered}
                                              color={C.teal}
                                          />
                                          <MetricCell
                                              pair={r.returned}
                                              color={C.red}
                                          />
                                      </tr>
                                  ))
                                : parcels.byCourier.map((r) => (
                                      <tr
                                          key={r.name}
                                          className="border-b border-border last:border-0 hover:bg-muted/40"
                                      >
                                          <td className="px-4 py-4 first:pl-6">
                                              <div className="flex items-center gap-3">
                                                  <CourierLogo
                                                      large
                                                      c={{
                                                          name: r.name,
                                                          logoUrl: r.logoUrl,
                                                      }}
                                                  />
                                                  <div className="min-w-0">
                                                      <p className="truncate font-semibold">
                                                          {r.name}
                                                      </p>
                                                      <p className="truncate text-xs text-muted-foreground">
                                                          {r.businesses.join(
                                                              ' · ',
                                                          )}
                                                      </p>
                                                  </div>
                                              </div>
                                          </td>
                                          <td className="px-4 py-4 font-semibold tabular-nums">
                                              {r.parcels.toLocaleString()}
                                          </td>
                                          <MetricCell
                                              pair={r.shipped}
                                              color={C.petrol}
                                          />
                                          <MetricCell
                                              pair={r.delivered}
                                              color={C.teal}
                                          />
                                          <MetricCell
                                              pair={r.returned}
                                              color={C.red}
                                          />
                                      </tr>
                                  ))}
                            {!count && (
                                <EmptyRow colSpan={5}>
                                    {t('No data for this selection.')}
                                </EmptyRow>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="border-t border-border px-6 py-3 text-xs text-muted-foreground">
                    {tab === 'business'
                        ? t(
                              count === 1
                                  ? ':count business'
                                  : ':count businesses',
                              { count },
                          )
                        : t(
                              count === 1
                                  ? ':count courier'
                                  : ':count couriers',
                              { count },
                          )}{' '}
                    · {t('scroll to see all')}
                </div>
            </div>
        </section>
    );
}

/* ─────────────── 4 · Team: confirmers / fulfilment ───────────────── */
function TeamCard({ team }: Pick<Props, 'team'>) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<'confirmers' | 'fulfilment'>('confirmers');
    const count = team[tab].length;
    const maxParcels = Math.max(...team.fulfilment.map((m) => m.parcels), 1);

    return (
        <section data-slot="sa.team">
            <div className="rounded-xl border border-border bg-card shadow-xs">
                <div className="flex flex-wrap items-center justify-between gap-4 p-6 pb-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {t('Team')}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {tab === 'confirmers'
                                ? t('Confirmers across your businesses')
                                : t('Fulfilment across your businesses')}
                        </p>
                    </div>
                    <SegmentedTabs
                        value={tab}
                        onChange={setTab}
                        options={[
                            { value: 'confirmers', label: t('Confirmers') },
                            { value: 'fulfilment', label: t('Fulfilment') },
                        ]}
                    />
                </div>
                <div className="max-h-[352px] overflow-x-auto overflow-y-auto border-t border-border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 z-10 bg-card">
                            <tr className="border-b border-border">
                                <Th>{t('Member')}</Th>
                                {tab === 'confirmers' ? (
                                    <>
                                        <Th>{t('Orders')}</Th>
                                        <Th>{t('Confirmation')}</Th>
                                        <Th>{t('Delivery')}</Th>
                                    </>
                                ) : (
                                    <Th>{t('Parcels managed')}</Th>
                                )}
                                <Th>{t('Commissions')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {tab === 'confirmers'
                                ? team.confirmers.map((m) => (
                                      <tr
                                          key={m.id}
                                          className="border-b border-border last:border-0 hover:bg-muted/40"
                                      >
                                          <td className="px-4 py-4 first:pl-6">
                                              <Who
                                                  initials={m.initials}
                                                  name={m.name}
                                                  sub={m.business}
                                              />
                                          </td>
                                          <td className="px-4 py-4 font-semibold tabular-nums">
                                              {m.orders.toLocaleString()}
                                          </td>
                                          <MetricCell
                                              pair={m.confirmed}
                                              color={C.orange}
                                          />
                                          <MetricCell
                                              pair={m.delivered}
                                              color={C.teal}
                                          />
                                          <Money value={m.commissionsMad} />
                                      </tr>
                                  ))
                                : team.fulfilment.map((m) => (
                                      <tr
                                          key={m.id}
                                          className="border-b border-border last:border-0 hover:bg-muted/40"
                                      >
                                          <td className="px-4 py-4 first:pl-6">
                                              <Who
                                                  initials={m.initials}
                                                  name={m.name}
                                                  sub={m.business}
                                              />
                                          </td>
                                          <td className="px-4 py-4">
                                              <span className="font-semibold tabular-nums">
                                                  {m.parcels.toLocaleString()}
                                              </span>{' '}
                                              <span className="text-xs text-muted-foreground">
                                                  {t('parcels')}
                                              </span>
                                              <div className="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full bg-muted">
                                                  <div
                                                      className="h-full rounded-full"
                                                      style={{
                                                          width: `${(m.parcels / maxParcels) * 100}%`,
                                                          background: C.teal,
                                                      }}
                                                  />
                                              </div>
                                          </td>
                                          <Money value={m.commissionsMad} />
                                      </tr>
                                  ))}
                            {!count && (
                                <EmptyRow colSpan={5}>
                                    {t(
                                        'No team members for this selection. Try another business.',
                                    )}
                                </EmptyRow>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="border-t border-border px-6 py-3 text-xs text-muted-foreground">
                    {tab === 'confirmers'
                        ? t(
                              count === 1
                                  ? ':count confirmer'
                                  : ':count confirmers',
                              { count },
                          )
                        : t(
                              count === 1
                                  ? ':count fulfilment member'
                                  : ':count fulfilment members',
                              { count },
                          )}{' '}
                    · {t('scroll to see all')}
                </div>
            </div>
        </section>
    );
}

/* ────────────────────────── loading skeleton ─────────────────────── */
function DashboardSkeleton() {
    return (
        <div className="space-y-4">
            {['h-72', 'h-64', 'h-64', 'h-64'].map((h, i) => (
                <div
                    key={i}
                    className="rounded-xl border border-border bg-card p-6 shadow-xs"
                >
                    <Skeleton className="h-5 w-40" />
                    <Skeleton className={`mt-5 w-full rounded-xl ${h}`} />
                </div>
            ))}
        </div>
    );
}

/* ────────────────────────────── page ─────────────────────────────── */
export default function SuperAdminDashboard(props: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const firstName = auth.user?.name?.split(' ')[0] ?? '';

    const { businesses, rangeLabel, ...filters } = props.filters;
    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters<FilterValues>(dashboard().url, filters, FILTER_KEYS);

    // The skeleton flashes while the server recomputes a new selection, so
    // the old numbers never sit under the new filter.
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const offStart = router.on('start', (event) => {
            if (event.detail.visit.url.pathname === dashboard().url) {
                setLoading(true);
            }
        });
        const offFinish = router.on('finish', () => setLoading(false));

        return () => {
            offStart();
            offFinish();
        };
    }, []);

    return (
        <SuperAdminLayout fullWidth>
            <Head title={t('Dashboard')} />

            <div className="flex flex-wrap items-end justify-between gap-6 pb-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {greeting(t)}, {firstName}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t("Here's how your businesses are doing.")} ·{' '}
                        {rangeLabel}
                    </p>
                </div>
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="sa-business-filter">
                            {t('Businesses')}
                        </Label>
                        <Select
                            value={draft.business ?? ALL}
                            onValueChange={(value) =>
                                updateFilters({
                                    business: value === ALL ? undefined : value,
                                })
                            }
                        >
                            <SelectTrigger
                                id="sa-business-filter"
                                className="w-48"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('All businesses')}
                                </SelectItem>
                                {businesses.map((b) => (
                                    <SelectItem key={b.id} value={String(b.id)}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <PeriodFilter
                        draft={{
                            ...draft,
                            period: draft.period ?? DEFAULT_PERIOD,
                        }}
                        onChange={updateFilters}
                        idPrefix="sa"
                    />
                    {hasActiveFilters && (
                        <DataTableResetFiltersButton onReset={resetFilters} />
                    )}
                </div>
            </div>

            {loading ? (
                <DashboardSkeleton />
            ) : (
                <div className="space-y-4">
                    <TrendCard trend={props.trend} />
                    <BusinessesCard businesses={props.businesses} />
                    <ParcelsCard parcels={props.parcels} />
                    <TeamCard team={props.team} />
                </div>
            )}
        </SuperAdminLayout>
    );
}
