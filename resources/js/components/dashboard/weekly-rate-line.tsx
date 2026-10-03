import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { useTranslation } from '@/hooks/use-translation';
export type WeeklyRatePoint = {
    /** Label for the week, e.g. "Jun 2" (week start). */
    week: string;
    /** Percentage 0-100; null = no volume that week. */
    rate: number | null;
};

export type WeeklyRateSeries = {
    key: string;
    label: string;
    color: string;
    data: WeeklyRatePoint[];
};

/**
 * One or more rates over time on a shared axis — confirmation, delivery,
 * return. The page's period filter owns the window; this component only
 * renders the buckets it's given. Weekly buckets are the default for long
 * windows (per-day rates on COD volume are mostly noise) — at wiring time
 * the server should send daily buckets for short presets (Today / 7 days)
 * and weekly ones beyond that, same axis either way. The Y axis autoscales
 * from zero so a low-magnitude series (return rate around 8%) still shows
 * its drift instead of flattening under a fixed 0-100 domain.
 */
export function WeeklyRateLine({
    series,
    className = 'h-64 w-full',
}: {
    series: WeeklyRateSeries[];
    className?: string;
}) {
    const { t } = useTranslation();

    const chartConfig = Object.fromEntries(
        series.map((entry) => [
            entry.key,
            { label: entry.label, color: entry.color },
        ]),
    ) satisfies ChartConfig;

    // Aligned by week label, not array index, so a series missing a week
    // renders as a gap instead of silently shifting sideways.
    const weeks = [
        ...new Set(series.flatMap((entry) => entry.data.map((p) => p.week))),
    ];

    const chartData = weeks.map((week) => {
        const row: Record<string, string | number | null> = { week };

        for (const entry of series) {
            row[entry.key] =
                entry.data.find((point) => point.week === week)?.rate ?? null;
        }

        return row;
    });

    const hasData = chartData.some((row) =>
        series.some((entry) => row[entry.key] !== null),
    );

    if (!hasData) {
        return (
            <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                {t('No data for this period yet.')}
            </div>
        );
    }

    return (
        <ChartContainer config={chartConfig} className={className}>
            <LineChart
                accessibilityLayer
                data={chartData}
                margin={{ top: 12, right: 12 }}
            >
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="week"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                />
                <YAxis
                    domain={[0, 'auto']}
                    tickLine={false}
                    axisLine={false}
                    width={36}
                    tickFormatter={(value) => `${value}%`}
                />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            formatter={(value, name) => (
                                <>
                                    <span className="text-muted-foreground">
                                        {chartConfig[name as string]?.label ??
                                            name}
                                    </span>
                                    <span className="ml-auto font-mono font-medium tabular-nums">
                                        {value}%
                                    </span>
                                </>
                            )}
                        />
                    }
                />
                {series.map((entry) => (
                    <Line
                        key={entry.key}
                        dataKey={entry.key}
                        type="monotone"
                        stroke={`var(--color-${entry.key})`}
                        strokeWidth={2}
                        dot={{ r: 3, fill: `var(--color-${entry.key})` }}
                    />
                ))}
                {series.length > 1 && (
                    <ChartLegend content={<ChartLegendContent />} />
                )}
            </LineChart>
        </ChartContainer>
    );
}
