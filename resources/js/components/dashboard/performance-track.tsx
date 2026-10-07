import { ChevronsUpDown } from 'lucide-react';
import type { MouseEvent } from 'react';
import { useState } from 'react';
import type { SimpleOption } from '@/components/dashboard/dashboard-filters';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/* ════════════════════════ TYPES ════════════════════════ */
export type Pair = [count: number, ratePct: number];
export interface Bucket {
    label: string;
    value: number;
}

export type Status = 'Excellent' | 'Good' | 'Average' | 'Low';

export interface PerfAgentRow {
    name: string;
    attainmentPct: number;
    status: Status;
    confPct: number;
    delivPct: number;
}
export type PerfView =
    | {
          type: 'team';
          attainmentPct: number;
          status: Status;
          confPct: number;
          delivPct: number;
          agents: PerfAgentRow[];
      }
    | {
          type: 'agent';
          name: string;
          ordersHandled: number;
          attainmentPct: number;
          status: Status;
          confPct: number;
          delivPct: number;
          commissionsMad: number;
      };

export interface PerformanceTrackData {
    rangeLabel: string;
    daily: Bucket[];
    ordersLabel: string;
    outcomes: {
        conf: Pair;
        deliv: Pair;
        ret: Pair;
        commissionsMad: number;
        commissionsSub: string;
    };
    goals: { conf: number; deliv: number };
    view: PerfView;
}

/* ════════════════════════ DESIGN TOKENS ════════════════════════ */
// Semantic colours per the handoff — never repainted.
export const C = {
    blue: '#16A08E',
    petrol: '#468FA5',
    purple: '#F2602F',
    green: '#16A08E',
    greenText: '#0C7D6F',
    amber: '#EFA22C',
    amberText: '#A87110',
    red: '#D92D20',
    redText: '#B3281D',
    gray: '#A1A1AA',
};
export const statusColor: Record<Status, string> = {
    Excellent: C.green,
    Good: C.petrol,
    Average: C.amber,
    Low: C.red,
};
export const badgeCls: Record<string, string> = {
    [C.green]: 'bg-[#16A08E]/10 text-[#0C7D6F]',
    [C.petrol]: 'bg-[#468FA5]/10 text-[#2E7389]',
    [C.amber]: 'bg-[#EFA22C]/12 text-[#A87110]',
    [C.red]: 'bg-[#D92D20]/10 text-[#B3281D]',
};

export const n = (value: number) => value.toLocaleString();
export const money = (value: number) => Math.round(value).toLocaleString();

/* ════════════════════════ SMALL PIECES ════════════════════════ */
/** Cursor-following chart tooltip (shared). */
export function useChartTip() {
    const [tip, setTip] = useState<{
        v: string;
        d: string;
        x: number;
        y: number;
    } | null>(null);
    const move = (e: MouseEvent, v: string, d: string) =>
        setTip({ v, d, x: e.clientX, y: e.clientY });
    const node = tip && (
        <div
            className="pointer-events-none fixed z-[100] rounded-md bg-foreground px-2.5 py-1.5 text-xs text-background shadow-md"
            style={{ left: tip.x + 12, top: tip.y - 44 }}
        >
            <div className="font-semibold tabular-nums">{tip.v}</div>
            <div className="mt-0.5 opacity-70">{tip.d}</div>
        </div>
    );

    return { move, hide: () => setTip(null), node };
}

export function Ring({
    pct,
    color,
    size,
    stroke,
}: {
    pct: number;
    color: string;
    size: number;
    stroke: number;
}) {
    const r = (size - stroke) / 2 - 1;
    const circ = 2 * Math.PI * r;

    return (
        <svg
            viewBox={`0 0 ${size} ${size}`}
            className="-rotate-90"
            width={size}
            height={size}
        >
            <circle
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke="var(--border)"
                strokeWidth={stroke}
            />
            <circle
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={color}
                strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={`${(Math.min(pct, 100) / 100) * circ} ${circ}`}
            />
        </svg>
    );
}

export function StatusBadge({ status, t }: { status: Status; t: Translator }) {
    return (
        <span
            className={cn(
                'rounded-full px-2.5 py-1 text-xs font-semibold',
                badgeCls[statusColor[status]],
            )}
        >
            {t(status)}
        </span>
    );
}

function GoalBar({
    label,
    actual,
    goal,
}: {
    label: string;
    actual: number;
    goal: number;
}) {
    const { t } = useTranslation();
    const col = actual >= goal ? C.green : actual >= goal - 8 ? C.amber : C.red;

    return (
        <div>
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium">{label}</span>
                <span className="text-sm tabular-nums">
                    <b className="font-semibold" style={{ color: col }}>
                        {actual}%
                    </b>{' '}
                    <span className="text-muted-foreground">
                        / {goal} {t('goal')}
                    </span>
                </span>
            </div>
            <div className="relative mt-2 h-2 rounded-full bg-muted">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(actual, 100)}%`,
                        background: col,
                    }}
                />
                <span
                    className="absolute top-1/2 h-3.5 w-0.5 -translate-y-1/2 rounded bg-foreground/60"
                    style={{ left: `${Math.min(goal, 100)}%` }}
                    title={t('goal')}
                />
            </div>
        </div>
    );
}

/**
 * The Performance track card. With `agents` it carries the agent
 * selector (admin); without, it shows one agent's own figures.
 */
export function PerformanceTrack({
    performance,
    agents,
    agentId,
    onAgentChange,
}: {
    performance: PerformanceTrackData;
    agents?: SimpleOption[];
    agentId?: string;
    onAgentChange?: (agentId: string | undefined) => void;
}) {
    const { t } = useTranslation();
    const { move, hide, node } = useChartTip();
    const daily = performance.daily;
    const max = Math.max(...daily.map((b) => b.value), 1);
    const total = daily.reduce((s, b) => s + b.value, 0);
    const v = performance.view;
    const ALL = '__all__';

    const axisLabels =
        daily.length === 0
            ? []
            : [0, 1, 2, 3].map(
                  (i) =>
                      daily[
                          Math.min(
                              Math.round((i * (daily.length - 1)) / 3),
                              daily.length - 1,
                          )
                      ].label,
              );

    const outcomes = performance.outcomes;
    const blob = (value: number, min: number, maxH: number, scale: number) =>
        Math.max(min, Math.min(maxH, Math.round(value * scale)));
    const outcomeCols: {
        label: string;
        big: string;
        col: string;
        bh: number;
        sub: string;
        arr: string;
    }[] = [
        {
            label: t('Confirmed'),
            big: `${outcomes.conf[1]}%`,
            col: C.purple,
            bh: blob(outcomes.conf[1], 20, 80, 0.85),
            sub: n(outcomes.conf[0]),
            arr: '↗',
        },
        {
            label: t('Delivered'),
            big: `${outcomes.deliv[1]}%`,
            col: C.green,
            bh: blob(outcomes.deliv[1], 20, 80, 0.85),
            sub: n(outcomes.deliv[0]),
            arr: '↗',
        },
        {
            label: t('Returned'),
            big: `${outcomes.ret[1]}%`,
            col: C.red,
            bh: blob(outcomes.ret[1], 12, 80, 2.2),
            sub: n(outcomes.ret[0]),
            arr: '↘',
        },
        {
            label: t('Commissions'),
            big: money(outcomes.commissionsMad),
            col: C.amber,
            bh: 38,
            sub: outcomes.commissionsSub,
            arr: '↗',
        },
    ];

    return (
        <section data-slot="perf.section">
            {node}
            <Card className="p-6">
                <CardContent className="p-0">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                {t('Performance track')}
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {t('Orders, outcomes & goals')} ·{' '}
                                {performance.rangeLabel}
                            </p>
                        </div>
                        {agents && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="dashboard-agent-filter">
                                    {t('Agent')}
                                </Label>
                                <Select
                                    value={agentId ?? ALL}
                                    onValueChange={(value) =>
                                        onAgentChange?.(
                                            value === ALL ? undefined : value,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="dashboard-agent-filter"
                                        className="w-44 bg-card"
                                    >
                                        <SelectValue />
                                        <ChevronsUpDown className="size-4 opacity-50" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            {t('All agents')}
                                        </SelectItem>
                                        {agents.map((a) => (
                                            <SelectItem
                                                key={a.id}
                                                value={String(a.id)}
                                            >
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 grid gap-6 lg:grid-cols-3">
                        {/* chart + outcomes */}
                        <div className="lg:col-span-2">
                            <div className="flex items-baseline justify-between">
                                <p className="text-sm text-muted-foreground">
                                    <span className="text-base font-semibold text-foreground tabular-nums">
                                        {n(total)}
                                    </span>{' '}
                                    {performance.ordersLabel}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t('peak')}{' '}
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {n(max)}
                                    </span>
                                    /{t('day')}
                                </p>
                            </div>
                            <div className="mt-4 flex h-52 items-end gap-[3px]">
                                {daily.map((b, i) => (
                                    <span
                                        key={i}
                                        className="relative flex-1 cursor-default self-stretch"
                                        onMouseMove={(e) =>
                                            move(
                                                e,
                                                `${n(b.value)} ${t('orders')}`,
                                                b.label,
                                            )
                                        }
                                        onMouseLeave={hide}
                                    >
                                        <i
                                            className={cn(
                                                'absolute bottom-0 w-full rounded-t-[4px] transition-colors',
                                                b.value === max
                                                    ? 'bg-[#16A08E] hover:opacity-90'
                                                    : 'bg-[#A6DAD0] hover:bg-[#5FBFAF]',
                                            )}
                                            style={{
                                                height: `${(b.value / max) * 100}%`,
                                            }}
                                        />
                                    </span>
                                ))}
                            </div>
                            <div className="mt-2 flex justify-between text-[11px] text-muted-foreground">
                                {axisLabels.map((label, i) => (
                                    <span key={i}>{label}</span>
                                ))}
                            </div>

                            <div
                                className="mt-5 grid grid-cols-2 gap-y-6 border-t border-border pt-5 sm:grid-cols-4"
                                data-slot="perf.outcomes"
                            >
                                {outcomeCols.map((o, i) => (
                                    <div
                                        key={o.label}
                                        className={cn(
                                            'flex flex-col px-4 first:pl-0 last:pr-0',
                                            i > 0 &&
                                                'border-l border-dashed border-border',
                                        )}
                                    >
                                        <p className="text-sm text-muted-foreground">
                                            {o.label}
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {o.big}
                                        </p>
                                        <div className="mt-auto w-full pt-4">
                                            <div
                                                className="w-full rounded-xl"
                                                style={{
                                                    height: o.bh,
                                                    background: o.col,
                                                }}
                                            />
                                        </div>
                                        <p className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                                            <span className="tabular-nums">
                                                {o.sub}
                                            </span>
                                            <span
                                                className="font-semibold"
                                                style={{ color: C.greenText }}
                                            >
                                                {o.arr}
                                            </span>
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* goals panel */}
                        <div
                            className="flex flex-col gap-5 border-t border-border pt-5 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6"
                            data-slot="perf.goals"
                        >
                            <p className="text-sm font-medium text-muted-foreground">
                                {t('Goal tracking')}{' '}
                                <span className="font-normal">
                                    · {t('vs targets you set per agent')}
                                </span>
                            </p>
                            {v.type === 'team' ? (
                                <>
                                    <div className="flex items-center gap-4 rounded-lg bg-muted/50 p-3.5">
                                        <div className="relative size-16 shrink-0">
                                            <Ring
                                                pct={v.attainmentPct}
                                                color={statusColor[v.status]}
                                                size={64}
                                                stroke={6}
                                            />
                                            <span className="absolute inset-0 flex items-center justify-center text-xs font-semibold tabular-nums">
                                                {v.attainmentPct}%
                                            </span>
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold">
                                                {t('Whole team')}
                                            </p>
                                            <p className="font-mono text-[11px] text-muted-foreground">
                                                {t('Conf')} {v.confPct}% /{' '}
                                                {performance.goals.conf} ·{' '}
                                                {t('Deliv')} {v.delivPct}% /{' '}
                                                {performance.goals.deliv}
                                            </p>
                                        </div>
                                        <StatusBadge status={v.status} t={t} />
                                    </div>
                                    <div className="border-t border-border" />
                                    {v.agents.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                'No agent activity in this period.',
                                            )}
                                        </p>
                                    )}
                                    {v.agents.map((a) => (
                                        <div
                                            key={a.name}
                                            className="flex items-center gap-3.5"
                                        >
                                            <div className="relative size-12 shrink-0">
                                                <Ring
                                                    pct={a.attainmentPct}
                                                    color={
                                                        statusColor[a.status]
                                                    }
                                                    size={48}
                                                    stroke={5}
                                                />
                                                <span className="absolute inset-0 flex items-center justify-center text-[10px] font-semibold tabular-nums">
                                                    {a.attainmentPct}%
                                                </span>
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold">
                                                    {a.name}
                                                </p>
                                                <p className="truncate font-mono text-[11px] text-muted-foreground">
                                                    {t('Conf')} {a.confPct}% /{' '}
                                                    {performance.goals.conf} ·{' '}
                                                    {t('Deliv')} {a.delivPct}% /{' '}
                                                    {performance.goals.deliv}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                status={a.status}
                                                t={t}
                                            />
                                        </div>
                                    ))}
                                </>
                            ) : (
                                <>
                                    <div className="flex items-center gap-4 rounded-lg bg-muted/50 p-3.5">
                                        <div className="relative size-16 shrink-0">
                                            <Ring
                                                pct={v.attainmentPct}
                                                color={statusColor[v.status]}
                                                size={64}
                                                stroke={6}
                                            />
                                            <span className="absolute inset-0 flex items-center justify-center text-xs font-semibold tabular-nums">
                                                {v.attainmentPct}%
                                            </span>
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold">
                                                {v.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    ':count orders handled · this period',
                                                    {
                                                        count: n(
                                                            v.ordersHandled,
                                                        ),
                                                    },
                                                )}
                                            </p>
                                        </div>
                                        <StatusBadge status={v.status} t={t} />
                                    </div>
                                    <div className="flex flex-col gap-5 pt-1">
                                        <GoalBar
                                            label={t('Confirmation rate')}
                                            actual={v.confPct}
                                            goal={performance.goals.conf}
                                        />
                                        <GoalBar
                                            label={t('Delivery rate')}
                                            actual={v.delivPct}
                                            goal={performance.goals.deliv}
                                        />
                                    </div>
                                    <div className="mt-auto flex items-center justify-between border-t border-border pt-4">
                                        <span className="text-sm text-muted-foreground">
                                            {t('Commissions earned')}
                                        </span>
                                        <span className="text-sm font-semibold tabular-nums">
                                            {money(v.commissionsMad)} MAD
                                        </span>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}
