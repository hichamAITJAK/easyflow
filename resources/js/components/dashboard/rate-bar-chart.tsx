import type { ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, ReferenceLine, XAxis, YAxis } from 'recharts';
import {
    ChartRangeSelect,
    useChartRange,
} from '@/components/dashboard/chart-range';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatDate } from '@/lib/format';

type Point = { date: string; rate: number | null };

type Series = {
    key: string;
    label: string;
    color?: string;
    data: Point[];
};

const dayLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', { weekday: 'short' });

const fullDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

/**
 * A percentage-trend bar chart, pinned to a 0-100 scale so a rate is always
 * read against the same baseline rather than an autoscaled one. Takes one or
 * more series sharing a date axis, drawn as grouped bars — plotting
 * confirmation and delivery rates together lets a manager see whether the two
 * move with each other or apart, which two separate cards can't show. Days
 * with no orders carry a null rate and simply draw no bar, which reads as a
 * gap rather than a false zero. An optional target draws a dashed reference
 * line so a rate can be read against a threshold at a glance. An optional
 * total renders as a headline figure beside the title — it is the caller's
 * full-period rate, computed from summed counts, and is deliberately
 * labelled with that period since the range selector only narrows the bars
 * below it, not the total.
 */
export function RateBarChart({
    series,
    title,
    target,
    total,
    totalPeriodDays,
    headerExtra,
}: {
    series: Series[];
    title: string;
    target?: number;
    /**
     * Full-period rate shown as a headline figure. Null renders as "--"
     * (no volume to divide by, which is not the same as 0%); omit the prop
     * entirely to render no headline at all.
     */
    total?: number | null;
    /** Period the total covers, used for its caption. */
    totalPeriodDays?: number;
    /** Extra header controls (e.g. an agent filter) next to the range picker. */
    headerExtra?: ReactNode;
}) {
    const chartConfig = Object.fromEntries(
        series.map((entry, index) => [
            entry.key,
            {
                label: entry.label,
                color: entry.color ?? `var(--color-chart-${index + 1})`,
            },
        ]),
    ) satisfies ChartConfig;

    // Series are aligned by date rather than by array index, so a series that
    // is missing a day renders as a gap instead of silently shifting sideways.
    const dates = [
        ...new Set(series.flatMap((entry) => entry.data.map((p) => p.date))),
    ].sort();

    const { take, days, selectProps } = useChartRange(dates.length);

    const chartData = take(dates).map((date) => {
        const row: Record<string, string | number | null> = { date };

        for (const entry of series) {
            row[entry.key] =
                entry.data.find((point) => point.date === date)?.rate ?? null;
        }

        return row;
    });

    const hasData = chartData.some((row) =>
        series.some((entry) => row[entry.key] !== null),
    );

    return (
        <Card className="pt-0 shadow-none">
            <CardHeader className="flex items-center gap-2 space-y-0 border-b py-5 sm:flex-row">
                <div className="grid flex-1 gap-1">
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>
                        {days} day{days === 1 ? '' : 's'} shown
                    </CardDescription>
                </div>

                {total !== undefined && (
                    <div className="grid gap-0.5 sm:text-right">
                        <span className="text-2xl font-semibold tabular-nums">
                            {total === null ? '—' : `${total}%`}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {totalPeriodDays
                                ? `Last ${totalPeriodDays} days`
                                : 'Full period'}
                        </span>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {headerExtra}
                    <ChartRangeSelect {...selectProps} />
                </div>
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                {hasData ? (
                    <ChartContainer
                        config={chartConfig}
                        className="aspect-auto h-[250px] w-full"
                    >
                        <BarChart
                            accessibilityLayer
                            data={chartData}
                            margin={{ top: 12 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={10}
                                minTickGap={24}
                                tickFormatter={dayLabel}
                            />
                            <YAxis domain={[0, 100]} hide />

                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        indicator="dashed"
                                        labelFormatter={(_, payload) =>
                                            fullDateLabel(
                                                String(
                                                    payload?.[0]?.payload.date,
                                                ),
                                            )
                                        }
                                        formatter={(value, name) => (
                                            <>
                                                <span className="text-muted-foreground">
                                                    {chartConfig[name as string]
                                                        ?.label ?? name}
                                                </span>
                                                <span className="ml-auto font-mono font-medium tabular-nums">
                                                    {value}%
                                                </span>
                                            </>
                                        )}
                                    />
                                }
                            />

                            {target !== undefined && (
                                <ReferenceLine
                                    y={target}
                                    stroke="var(--color-muted-foreground)"
                                    strokeDasharray="4 3"
                                    strokeOpacity={0.6}
                                    label={{
                                        value: `Target ${target}%`,
                                        position: 'insideTopRight',
                                        className:
                                            'fill-muted-foreground text-[10px]',
                                    }}
                                />
                            )}

                            {series.map((entry) => (
                                <Bar
                                    key={entry.key}
                                    dataKey={entry.key}
                                    fill={`var(--color-${entry.key})`}
                                    radius={4}
                                />
                            ))}

                            {series.length > 1 && (
                                <ChartLegend content={<ChartLegendContent />} />
                            )}
                        </BarChart>
                    </ChartContainer>
                ) : (
                    <div className="flex h-[250px] items-center justify-center text-sm text-muted-foreground">
                        No data for this period yet.
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
