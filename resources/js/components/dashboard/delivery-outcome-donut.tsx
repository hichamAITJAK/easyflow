import { useMemo } from 'react';
import { Pie, PieChart } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
type Outcome = { outcome: string; count: number };

// Semantic slot mapping, not slot order: teal for the good outcome, warm
// warns for returned/refused, navy for cancelled. Also keeps chart-1 and
// chart-2 (near-identical luminance) from ever being adjacent slices.
const buildChartConfig = (t: Translator) => ({
    count: { label: t('Orders') },
    Delivered: { label: t('Delivered'), color: 'var(--color-chart-2)' },
    Returned: { label: t('Returned'), color: 'var(--color-chart-5)' },
    Refused: { label: t('Refused'), color: 'var(--color-chart-1)' },
    Cancelled: { label: t('Cancelled'), color: 'var(--color-chart-3)' },
}) satisfies ChartConfig;

/**
 * Delivered/returned/refused/cancelled as a share of shipped orders — fixed
 * categorical color order (slots 1-4), not a status ramp: this is "which
 * bucket" identity, not a good/bad severity scale.
 */
export function DeliveryOutcomeDonut({ data }: { data: Outcome[] }) {
    const { t } = useTranslation();
    const chartConfig = useMemo(() => buildChartConfig(t), [t]);

    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (total === 0) {
        return (
            <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                {t('No shipped orders in this period yet.')}
            </div>
        );
    }

    const chartData = data.map((row) => ({
        outcome: row.outcome,
        count: row.count,
        fill: `var(--color-${row.outcome})`,
    }));

    return (
        <ChartContainer
            config={chartConfig}
            className="mx-auto aspect-square max-h-[250px]"
        >
            <PieChart>
                <ChartTooltip
                    cursor={false}
                    content={<ChartTooltipContent hideLabel />}
                />
                <Pie
                    data={chartData}
                    dataKey="count"
                    nameKey="outcome"
                    innerRadius={60}
                />
                <ChartLegend
                    content={<ChartLegendContent nameKey="outcome" />}
                    className="flex-wrap gap-2 *:basis-1/4 *:justify-center"
                />
            </PieChart>
        </ChartContainer>
    );
}
