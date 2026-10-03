import { useMemo, useState } from 'react';
import { PolarAngleAxis, RadialBar, RadialBarChart } from 'recharts';
import { AgentFilter } from '@/components/dashboard/agent-filter';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type AgentPerformance = {
    id: string;
    name: string;
    /** Profile photo URL; null falls back to initials. */
    avatar: string | null;
    confirmationRate: number;
    deliveryRate: number;
    orders: number;
};

export type PerformanceTargets = {
    confirmation: number;
    delivery: number;
};

// Teal + navy rings — the widest-luminance palette pair, so the two rings
// stay apart in grayscale too.
const buildChartConfig = (t: Translator) => ({
    confirmation: {
        label: t('Confirmation rate'),
        color: 'var(--color-chart-1)',
    },
    delivery: { label: t('Delivery success'), color: 'var(--color-chart-3)' },
}) satisfies ChartConfig;

function MetricRow({
    label,
    colorVar,
    value,
    target,
}: {
    label: string;
    colorVar: string;
    value: number;
    target: number;
}) {

    const met = value >= target;
    const diff = Math.abs(value - target).toFixed(1);

    return (
        <div className="flex items-center justify-between gap-2">
            <span className="flex items-center gap-2 text-sm">
                <span
                    aria-hidden
                    className="size-2.5 shrink-0 rounded-[2px]"
                    style={{ backgroundColor: `var(${colorVar})` }}
                />
                {label}
            </span>

            <span className="text-sm tabular-nums">
                <span className="font-semibold">{value.toFixed(1)}%</span>{' '}
                <span
                    className={cn(
                        'text-xs',
                        met ? 'text-success' : 'text-destructive',
                    )}
                >
                    {met ? '+' : '−'}
                    {diff} vs {target}% target
                </span>
            </span>
        </div>
    );
}

/**
 * One agent at a time against their targets: two concentric radial gauges
 * (confirmation outer, delivery inner), each filled to the agent's rate on
 * a 0-100 ring with the remainder as a muted track. Defaults to the agent
 * furthest below target — a manager's daily job is spotting who needs
 * help, not admiring the leader — and the picker answers "and how is
 * everyone else doing?" one agent at a time, which is the question a
 * radial can actually carry.
 */
export function TeamPerformanceRadial({
    agents,
    targets,
}: {
    agents: AgentPerformance[];
    targets: PerformanceTargets;
}) {
    const { t } = useTranslation();
    const chartConfig = useMemo(() => buildChartConfig(t), [t]);

    // Combined shortfall against both targets; most-negative first.
    const needsAttention = useMemo(
        () =>
            [...agents].sort(
                (a, b) =>
                    a.confirmationRate - targets.confirmation + (a.deliveryRate - targets.delivery) - (b.confirmationRate - targets.confirmation + (b.deliveryRate - targets.delivery)), )[0], [agents, targets], );
    const [agentId, setAgentId] = useState<string | undefined>();
    const getInitials = useInitials();

    const selected =
        agents.find((agent) => agent.id === agentId) ?? needsAttention;

    if (!selected) {
        return (
            <Card className="shadow-none">
                <CardHeader>
                    <CardTitle>{t('Team performance')}</CardTitle>
                    <CardDescription>{t('Agent vs target')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                        {t('No confirmation agents yet.')}
                    </div>
                </CardContent>
            </Card>
        );
    }

    const chartData = [
        // Inner ring first — recharts draws data order inside-out.
        {
            metric: 'delivery',
            value: selected.deliveryRate,
            fill: 'var(--color-delivery)',
        },
        {
            metric: 'confirmation',
            value: selected.confirmationRate,
            fill: 'var(--color-confirmation)',
        },
    ];

    return (
        <Card className="shadow-none">
            <CardHeader className="flex flex-wrap items-start justify-between gap-2 space-y-0">
                <div className="grid gap-1.5">
                    <CardTitle>{t('Team performance')}</CardTitle>
                    <CardDescription>
                        {agentId === undefined
                            ? t('Needs attention first')
                            : t('Agent vs target')}
                    </CardDescription>
                </div>
                <AgentFilter
                    agents={agents}
                    value={agentId}
                    onChange={setAgentId}
                    allLabel={t('Needs attention')}
                    ariaLabel={t('Select agent to review')}
                />
            </CardHeader>

            <CardContent>
                <ChartContainer
                    config={chartConfig}
                    className="mx-auto aspect-square max-h-[220px]"
                >
                    <RadialBarChart
                        accessibilityLayer
                        data={chartData}
                        innerRadius={56}
                        outerRadius={110}
                        startAngle={90}
                        endAngle={-270}
                        barSize={16}
                    >
                        <PolarAngleAxis
                            type="number"
                            domain={[0, 100]}
                            tick={false}
                            axisLine={false}
                        />
                        <RadialBar
                            dataKey="value"
                            background={{ fill: 'var(--color-muted)' }}
                            cornerRadius={8}
                        />
                        <text
                            x="50%"
                            y="47%"
                            textAnchor="middle"
                            dominantBaseline="middle"
                            className="fill-foreground text-2xl font-semibold tabular-nums"
                        >
                            {selected.confirmationRate.toFixed(0)}%
                        </text>
                        <text
                            x="50%"
                            y="58%"
                            textAnchor="middle"
                            dominantBaseline="middle"
                            className="fill-muted-foreground text-xs"
                        >
                            confirmed
                        </text>
                    </RadialBarChart>
                </ChartContainer>

                <div className="mt-4 space-y-2 border-t pt-4">
                    <div className="flex items-center gap-2.5">
                        <Avatar className="size-8">
                            {selected.avatar && (
                                <AvatarImage
                                    src={selected.avatar}
                                    alt=""
                                    className="object-cover"
                                />
                            )}
                            <AvatarFallback className="text-xs">
                                {getInitials(selected.name)}
                            </AvatarFallback>
                        </Avatar>
                        <p className="truncate text-sm font-semibold">
                            {selected.name}
                            <span className="ml-2 font-normal text-muted-foreground">
                                {selected.orders} orders
                            </span>
                        </p>
                    </div>

                    <MetricRow
                        label={t('Confirmation rate')}
                        colorVar="--color-chart-1"
                        value={selected.confirmationRate}
                        target={targets.confirmation}
                    />
                    <MetricRow
                        label={t('Delivery success')}
                        colorVar="--color-chart-3"
                        value={selected.deliveryRate}
                        target={targets.delivery}
                    />
                </div>
            </CardContent>
        </Card>
    );
}
