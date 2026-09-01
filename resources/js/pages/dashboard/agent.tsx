import { Head } from '@inertiajs/react';
import { RateBarChart } from '@/components/dashboard/rate-bar-chart';
import { StatTile } from '@/components/dashboard/stat-tile';
import { dashboard } from '@/routes';

type Props = {
    periodDays: number;
    totals: {
        assigned: number;
        confirmed: number;
        delivered: number;
        cancelled: number;
    };
    tileRates: {
        confirmed: number | null;
        delivered: number | null;
        cancelled: number | null;
    };
    confirmationRateTrend: { date: string; rate: number | null }[];
    confirmationRateTarget: string | null;
    deliveryRateTrend: { date: string; rate: number | null }[];
    deliveryRateTarget: string | null;
    rateTotals: {
        confirmation: number | null;
        delivery: number | null;
    };
    commissionEarned: number;
};

function money(value: number): string {
    return `${value.toFixed(2)} MAD`;
}

export default function AgentDashboard({
    periodDays,
    totals,
    tileRates,
    confirmationRateTrend,
    confirmationRateTarget,
    deliveryRateTrend,
    deliveryRateTarget,
    rateTotals,
    commissionEarned,
}: Props) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <p className="text-sm text-muted-foreground">
                    Last {periodDays} days
                </p>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    {/* Assigned carries no share: it is the denominator
                        the others are measured against, so "100%" would
                        be noise. */}
                    <StatTile label="Assigned" value={totals.assigned} />
                    <StatTile
                        label="Confirmed"
                        value={totals.confirmed}
                        caption="of assigned"
                        rate={tileRates.confirmed}
                    />
                    <StatTile
                        label="Delivered"
                        value={totals.delivered}
                        caption="of shipped"
                        rate={tileRates.delivered}
                        accent="success"
                    />
                    <StatTile
                        label="Cancelled"
                        value={totals.cancelled}
                        caption="of assigned"
                        rate={tileRates.cancelled}
                        accent="destructive"
                    />
                    <StatTile
                        label="Commission earned"
                        value={money(commissionEarned)}
                    />
                </div>

                {/* Two charts rather than one with grouped bars: the two
                    metrics carry different targets (80% vs 90% by
                    default), and RateBarChart draws a single reference
                    line — sharing one would mis-state the threshold for
                    whichever metric didn't own it. */}
                <RateBarChart
                    title="My confirmation rate"
                    total={rateTotals.confirmation}
                    totalPeriodDays={periodDays}
                    series={[
                        {
                            key: 'confirmation',
                            label: 'Confirmation rate',
                            data: confirmationRateTrend,
                        },
                    ]}
                    target={
                        confirmationRateTarget
                            ? Number(confirmationRateTarget)
                            : undefined
                    }
                />

                <RateBarChart
                    title="My delivery rate"
                    total={rateTotals.delivery}
                    totalPeriodDays={periodDays}
                    series={[
                        {
                            key: 'delivery',
                            label: 'Delivery rate',
                            color: 'var(--color-chart-3)',
                            data: deliveryRateTrend,
                        },
                    ]}
                    target={
                        deliveryRateTarget
                            ? Number(deliveryRateTarget)
                            : undefined
                    }
                />
            </div>
        </>
    );
}

AgentDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
