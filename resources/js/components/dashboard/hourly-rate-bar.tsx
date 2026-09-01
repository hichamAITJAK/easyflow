import { useId } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ReferenceDot,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

export type HourlyRatePoint = {
    /** Hour of day, 0-23. */
    hour: number;
    /** Confirmation rate for calls made that hour; null = no attempts. */
    rate: number | null;
    attempts: number;
};

const chartConfig = {
    rate: { label: 'Confirmation rate', color: 'var(--color-chart-1)' },
} satisfies ChartConfig;

const hourLabel = (hour: number) => `${String(hour).padStart(2, '0')}h`;

/** An hour needs this many calls before its rate can win "best hour" —
 *  without a floor, one lucky 23h call would become the recommendation
 *  managers schedule the team around. */
const MIN_ATTEMPTS_FOR_BEST = 10;

/**
 * Confirmation rate over the hours of the day, as a gradient area — the
 * daily calling rhythm reads as one continuous curve, and the single best
 * hour is pinned with a marked, labeled peak so the answer to "when
 * should we call?" is still one point, not a texture. Overnight hours
 * with no attempts break the area (a gap, not a zero — nobody called,
 * nothing failed), and low-attempt hours can't claim the peak.
 */
export function HourlyRateBar({ data }: { data: HourlyRatePoint[] }) {
    const fillId = `hourly-fill-${useId().replace(/:/g, '')}`;
    const active = data.filter((point) => point.rate !== null);

    if (active.length === 0) {
        return (
            <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                No confirmation activity in this period yet.
            </div>
        );
    }

    // Prefer hours with enough volume to trust; if none clear the floor
    // yet (young business, short period), fall back to all active hours.
    const eligible = active.filter(
        (point) => point.attempts >= MIN_ATTEMPTS_FOR_BEST,
    );

    const best = (eligible.length > 0 ? eligible : active).reduce(
        (top, point) => ((point.rate ?? 0) > (top.rate ?? 0) ? point : top),
    );

    return (
        <ChartContainer config={chartConfig} className="h-64 w-full">
            <AreaChart accessibilityLayer data={data} margin={{ top: 24 }}>
                <defs>
                    <linearGradient id={fillId} x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="5%"
                            stopColor="var(--color-rate)"
                            stopOpacity={0.8}
                        />
                        <stop
                            offset="95%"
                            stopColor="var(--color-rate)"
                            stopOpacity={0.1}
                        />
                    </linearGradient>
                </defs>

                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="hour"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    interval="preserveStartEnd"
                    minTickGap={16}
                    tickFormatter={(value) => hourLabel(Number(value))}
                />
                <YAxis domain={[0, 100]} hide />

                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_, payload) => {
                                const point = payload?.[0]?.payload as
                                    | HourlyRatePoint
                                    | undefined;

                                return point
                                    ? `${hourLabel(point.hour)} — ${point.attempts} call${point.attempts === 1 ? '' : 's'}`
                                    : '';
                            }}
                            formatter={(value) => (
                                <>
                                    <span className="text-muted-foreground">
                                        Confirmation rate
                                    </span>
                                    <span className="ml-auto font-mono font-medium tabular-nums">
                                        {value}%
                                    </span>
                                </>
                            )}
                        />
                    }
                />

                <Area
                    dataKey="rate"
                    type="monotone"
                    fill={`url(#${fillId})`}
                    stroke="var(--color-rate)"
                    strokeWidth={2}
                />

                {/* The one answer, pinned: marked dot + label on the best
                    hour so the peak survives the curve's smoothness. */}
                {best.rate !== null && (
                    <ReferenceDot
                        x={best.hour}
                        y={best.rate}
                        r={5}
                        fill="var(--color-rate)"
                        stroke="var(--color-background)"
                        strokeWidth={2}
                        label={{
                            value: `${hourLabel(best.hour)} · ${best.rate}%`,
                            position: 'top',
                            offset: 10,
                            className:
                                'fill-foreground text-xs font-semibold',
                        }}
                    />
                )}
            </AreaChart>
        </ChartContainer>
    );
}

/** Chart plus its methodology note; use this in cards. */
export function HourlyRateBarWithNote({ data }: { data: HourlyRatePoint[] }) {
    return (
        <>
            <HourlyRateBar data={data} />
            <p className="mt-2 text-xs text-muted-foreground">
                Best hour considers hours with at least{' '}
                {MIN_ATTEMPTS_FOR_BEST} calls.
            </p>
        </>
    );
}
