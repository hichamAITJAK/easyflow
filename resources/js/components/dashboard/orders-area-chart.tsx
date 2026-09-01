import { useId } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis } from 'recharts';
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
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatDate } from '@/lib/format';

type Point = { date: string; count: number };

const chartConfig = {
    count: { label: 'Orders', color: 'var(--color-chart-1)' },
} satisfies ChartConfig;

const shortDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', { month: 'short', day: 'numeric' });

const fullDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

/** Orders per day, with a range picker that narrows the window client-side. */
export function OrdersAreaChart({
    data,
    title = 'Orders per day',
}: {
    data: Point[];
    title?: string;
}) {
    const fillId = `orders-fill-${useId().replace(/:/g, '')}`;

    const { take, days, selectProps } = useChartRange(data.length);

    const visible = take(data);
    const allZero = visible.every((point) => point.count === 0);

    return (
        <Card className="pt-0 shadow-none">
            <CardHeader className="flex items-center gap-2 space-y-0 border-b py-5 sm:flex-row">
                <div className="grid flex-1 gap-1">
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>
                        {days} day{days === 1 ? '' : 's'} shown
                    </CardDescription>
                </div>

                <ChartRangeSelect {...selectProps} />
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                <ChartContainer
                    config={chartConfig}
                    className="aspect-auto h-[250px] w-full"
                >
                    <AreaChart accessibilityLayer data={visible}>
                        <defs>
                            <linearGradient
                                id={fillId}
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="5%"
                                    stopColor="var(--color-count)"
                                    stopOpacity={0.8}
                                />
                                <stop
                                    offset="95%"
                                    stopColor="var(--color-count)"
                                    stopOpacity={0.1}
                                />
                            </linearGradient>
                        </defs>

                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={32}
                            tickFormatter={shortDateLabel}
                        />

                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    indicator="dot"
                                    labelFormatter={(value) =>
                                        fullDateLabel(String(value))
                                    }
                                />
                            }
                        />

                        <Area
                            dataKey="count"
                            type="natural"
                            fill={`url(#${fillId})`}
                            stroke="var(--color-count)"
                        />
                    </AreaChart>
                </ChartContainer>

                {allZero && (
                    <p className="mt-2 text-center text-sm text-muted-foreground">
                        No orders in this period.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
