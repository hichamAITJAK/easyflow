import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
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
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format';

import type { Translator } from '@/lib/i18n';
export type OrdersPerDayPoint = { date: string; count: number };

const buildChartConfig = (t: Translator) => ({
    count: { label: t('Orders'), color: 'var(--color-chart-1)' },
}) satisfies ChartConfig;

const shortDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', { month: 'short', day: 'numeric' });

const fullDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

/**
 * The dashboard's lead chart: daily order volume as columns — orders
 * arrive as discrete daily counts, and a column per day reads each day as
 * the countable unit it is. One total series only: the page's store
 * filter already owns the "which channel" question. No per-chart range
 * picker either; the page period filter owns the window.
 */
export function OrdersPerDayCard({ orders }: { orders: OrdersPerDayPoint[] }) {
    const { t } = useTranslation();
    const chartConfig = useMemo(() => buildChartConfig(t), [t]);

    const allZero = orders.every((point) => point.count === 0);

    return (
        <Card className="pt-0 shadow-none">
            <CardHeader className="border-b py-5">
                <CardTitle>{t('Orders per day')}</CardTitle>
                <CardDescription>{t('Daily order volume')}</CardDescription>
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                <ChartContainer
                    config={chartConfig}
                    className="aspect-auto h-[280px] w-full"
                >
                    <BarChart
                        accessibilityLayer
                        data={orders}
                        margin={{ top: 12 }}
                    >
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={32}
                            tickFormatter={shortDateLabel}
                        />
                        <YAxis
                            domain={[0, 'auto']}
                            tickLine={false}
                            axisLine={false}
                            width={36}
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

                        <Bar
                            dataKey="count"
                            fill="var(--color-count)"
                            radius={4}
                        />
                    </BarChart>
                </ChartContainer>

                {allZero && (
                    <p className="mt-2 text-center text-sm text-muted-foreground">
                        {t('No orders in this period.')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
