import { Bar, BarChart, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

type Row = { label: string; count: number };

const humanizeCode = (code: string) =>
    code
        .split('_')
        .map((word) => word[0].toUpperCase() + word.slice(1))
        .join(' ');

/**
 * Ranked horizontal count bars — reason codes, stores, products. Ordinal
 * single-hue: position (most to least common) is the meaning here, not
 * per-row identity, so one color per card, chosen by the caller to keep
 * cards distinguishable from one another at a glance.
 *
 * `humanize` turns snake_case reason codes into words; leave it off for
 * real-world names (stores, products) that must render verbatim.
 */
export function RankedCountBar({
    data,
    color = 'var(--color-chart-5)',
    humanize = false,
    emptyMessage = 'Nothing recorded in this period yet.',
    maxRows = 8,
}: {
    data: Row[];
    color?: string;
    humanize?: boolean;
    emptyMessage?: string;
    maxRows?: number;
}) {
    const chartConfig = {
        count: { label: 'Orders', color },
    } satisfies ChartConfig;

    if (data.length === 0) {
        return (
            <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
                {emptyMessage}
            </div>
        );
    }

    const chartData = data.slice(0, maxRows).map((row) => ({
        label: humanize ? humanizeCode(row.label) : row.label,
        count: row.count,
    }));

    return (
        <ChartContainer config={chartConfig} className="h-64 w-full">
            <BarChart
                accessibilityLayer
                data={chartData}
                layout="vertical"
                margin={{ left: 8 }}
            >
                <XAxis type="number" dataKey="count" hide />
                <YAxis
                    dataKey="label"
                    type="category"
                    tickLine={false}
                    tickMargin={10}
                    axisLine={false}
                    width={140}
                />
                <ChartTooltip
                    cursor={false}
                    content={<ChartTooltipContent hideLabel />}
                />
                <Bar dataKey="count" fill="var(--color-count)" radius={5} />
            </BarChart>
        </ChartContainer>
    );
}

/**
 * Back-compat wrapper for the original reason-code API ({reason, count})
 * used by the agent dashboard.
 */
export function ReasonBreakdownBar({
    data,
    color,
}: {
    data: { reason: string; count: number }[];
    color?: string;
}) {
    return (
        <RankedCountBar
            data={data.map((row) => ({ label: row.reason, count: row.count }))}
            color={color}
            humanize
            emptyMessage="No reasons recorded in this period yet."
        />
    );
}
