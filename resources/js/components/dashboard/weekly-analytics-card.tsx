import { PackageCheck, TrendingDown, TrendingUp, XCircle } from 'lucide-react';
import { useMemo } from 'react';
import { Bar, BarChart, LabelList, XAxis } from 'recharts';
import {
    Card,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Item,
    ItemContent,
    ItemGroup,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { useTranslation } from '@/hooks/use-translation';
import { formatNumber } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * PLACEHOLDER DATA — this card is a design mock. Everything below is static
 * so the layout can be reviewed without touching the backend; swap for
 * server props once the design is approved.
 */
const FAKE_WEEK = [
    { day: 'Mon', orders: 148 },
    { day: 'Tue', orders: 132 },
    { day: 'Wed', orders: 165 },
    { day: 'Thu', orders: 179 },
    { day: 'Fri', orders: 154 },
    { day: 'Sat', orders: 208 },
    { day: 'Sun', orders: 186 },
];

/**
 * Confirmation rate lives on the team-performance card now (it shows the
 * same whole-team figure alongside the per-agent breakdown) — repeating it
 * here would just be the same number twice. Delivered/cancelled aren't
 * shown anywhere else on this page, so they stay.
 */
const FAKE_METRICS = [
    {
        title: 'Delivered',
        value: '82.1%',
        delta: -1.4,
        higherIsBetter: true,
        icon: PackageCheck,
        chip: 'bg-success/10 text-success',
    },
    {
        title: 'Cancelled',
        value: '12.6%',
        delta: 0.8,
        higherIsBetter: false,
        icon: XCircle,
        chip: 'bg-destructive/10 text-destructive',
    },
];

const buildChartConfig = (t: Translator) => ({
    orders: { label: t('Orders'), color: 'var(--color-chart-1)' },
}) satisfies ChartConfig;

function MetricRow({
    title,
    value,
    delta,
    higherIsBetter,
    icon: Icon,
    chip,
}: (typeof FAKE_METRICS)[number]) {

    const good = delta === 0 || delta > 0 === higherIsBetter;
    const TrendIcon = delta >= 0 ? TrendingUp : TrendingDown;

    return (
        <Item className="border-none p-0">
            <ItemMedia
                className={cn(
                    'size-10 rounded-lg [&_svg:not([class*="size-"])]:size-5',
                    chip,
                )}
            >
                <Icon />
            </ItemMedia>

            <ItemContent className="gap-0.5">
                <ItemTitle className="text-base font-semibold">
                    {title}
                </ItemTitle>
                <div className="flex items-baseline gap-2">
                    <p className="text-xl font-semibold tabular-nums">
                        {value}
                    </p>
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-xs font-medium tabular-nums',
                            good ? 'text-success' : 'text-destructive',
                        )}
                    >
                        <TrendIcon className="size-3" />
                        {delta > 0 ? '+' : ''}
                        {delta.toFixed(1)} pts
                    </span>
                </div>
            </ItemContent>
        </Item>
    );
}

/**
 * Weekly analytics: this week's order volume as a labeled bar chart
 * (two-thirds), a vertical divider, then this week's delivered/cancelled
 * rates with their change against last week (one-third) — volume and
 * outcome quality for the same week, read side by side.
 */
export function WeeklyAnalyticsCard() {
    const { t } = useTranslation();
    const chartConfig = useMemo(() => buildChartConfig(t), [t]);

    const total = FAKE_WEEK.reduce((sum, point) => sum + point.orders, 0);

    return (
        <Card className="py-0 shadow-none">
            <div className="grid md:grid-cols-3">
                <div className="p-6 md:col-span-2">
                    <CardTitle>{t('Total orders')}</CardTitle>
                    <CardDescription className="mt-1">
                        {formatNumber(total)} orders this week
                    </CardDescription>

                    <ChartContainer
                        config={chartConfig}
                        className="mt-4 aspect-auto h-[300px] w-full"
                    >
                        <BarChart
                            accessibilityLayer
                            data={FAKE_WEEK}
                            margin={{ top: 20 }}
                        >
                            <XAxis
                                dataKey="day"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={10}
                            />

                            <Bar
                                dataKey="orders"
                                fill="var(--color-orders)"
                                radius={8}
                            >
                                <LabelList
                                    position="top"
                                    offset={12}
                                    className="fill-foreground font-semibold"
                                    fontSize={14}
                                />
                            </Bar>
                        </BarChart>
                    </ChartContainer>
                </div>

                <div className="flex flex-col border-t p-6 md:border-t-0 md:border-l">
                    <CardTitle>{t('This week')}</CardTitle>
                    <CardDescription className="mt-1">
                        {t('Change vs last week')}
                    </CardDescription>

                    <ItemGroup className="flex-1 justify-center gap-6 py-6">
                        {FAKE_METRICS.map((metric) => (
                            <MetricRow key={metric.title} {...metric} />
                        ))}
                    </ItemGroup>
                </div>
            </div>
        </Card>
    );
}
