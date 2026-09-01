import { CartesianGrid, Line, LineChart, XAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

type Point = { date: string; rate: number | null };

// lastWeek gets navy, not teal — chart-1/chart-2 share luminance and the
// two lines can cross; the dash alone shouldn't carry the distinction.
const chartConfig = {
    thisWeek: { label: 'This week', color: 'var(--color-chart-1)' },
    lastWeek: { label: 'Last week', color: 'var(--color-chart-3)' },
} satisfies ChartConfig;

const dayLabel = (iso: string) =>
    new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'short',
    });

/**
 * This week's confirmation rate vs. the prior week, aligned by day-of-week
 * offset rather than calendar date — UC-24's week-over-week trend, so a
 * manager can see whether a change is helping or hurting, not just a
 * single snapshot number.
 */
export function WeekOverWeekLine({
    thisWeek,
    lastWeek,
}: {
    thisWeek: Point[];
    lastWeek: Point[];
}) {
    const chartData = thisWeek.map((point, index) => ({
        day: dayLabel(point.date),
        thisWeek: point.rate,
        lastWeek: lastWeek[index]?.rate ?? null,
    }));

    return (
        <ChartContainer config={chartConfig} className="h-64 w-full">
            <LineChart accessibilityLayer data={chartData}>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="day"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                />
                <ChartTooltip content={<ChartTooltipContent />} />
                <ChartLegend content={<ChartLegendContent />} />
                <Line
                    dataKey="thisWeek"
                    type="monotone"
                    stroke="var(--color-thisWeek)"
                    strokeWidth={2}
                    dot={false}
                    connectNulls
                />
                <Line
                    dataKey="lastWeek"
                    type="monotone"
                    stroke="var(--color-lastWeek)"
                    strokeWidth={2}
                    strokeDasharray="4 3"
                    dot={false}
                    connectNulls
                />
            </LineChart>
        </ChartContainer>
    );
}
