import { useMemo } from 'react';
import { Bar, BarChart, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
type Stage = { stage: string; count: number };

const buildChartConfig = (t: Translator) => ({
    count: { label: t('Orders'), color: 'var(--color-chart-3)' },
}) satisfies ChartConfig;

/**
 * 5-stage confirmation funnel (new -> assigned -> confirmed -> submitted to
 * courier -> delivered). Stage order carries the meaning here (it's a
 * pipeline position, not an independent identity), so this is an ordinal
 * single-hue bar, not a multi-slot categorical chart.
 */
export function ConfirmationFunnel({ data }: { data: Stage[] }) {
    const { t } = useTranslation();
    const chartConfig = useMemo(() => buildChartConfig(t), [t]);

    return (
        <ChartContainer config={chartConfig} className="h-64 w-full">
            <BarChart
                accessibilityLayer
                data={data}
                layout="vertical"
                margin={{ left: 8 }}
            >
                <XAxis type="number" dataKey="count" hide />
                <YAxis
                    dataKey="stage"
                    type="category"
                    tickLine={false}
                    tickMargin={10}
                    axisLine={false}
                    width={120}
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
