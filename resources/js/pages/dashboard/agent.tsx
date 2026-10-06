import { Head } from '@inertiajs/react';
import { RateBarChart } from '@/components/dashboard/rate-bar-chart';
import { StatTile } from '@/components/dashboard/stat-tile';
import { useTranslation } from '@/hooks/use-translation';
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
    const { t } = useTranslation();

    // "Confirmed" and the like are already translated as one order's
    // status. These tiles count many orders, which other languages word
    // differently, so they get their own entries and fall back to the
    // plain English word when there is none.
    const countLabel = (label: string): string => {
        const key = `${label} (count)`;
        const translated = t(key);

        return translated === key ? label : translated;
    };

    return (
        <>
            <Head title={t('Dashboard')} />

            <div className="space-y-6 p-4">
                <p className="text-sm text-muted-foreground">
                    {t('Last :days days', { days: periodDays })}
                </p>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    {/* Assigned carries no share: it is the denominator
                        the others are measured against, so "100%" would
                        be noise. */}
                    <StatTile
                        label={countLabel('Assigned')}
                        value={totals.assigned}
                    />
                    <StatTile
                        label={countLabel('Confirmed')}
                        value={totals.confirmed}
                        caption={t('of assigned')}
                        rate={tileRates.confirmed}
                    />
                    <StatTile
                        label={countLabel('Delivered')}
                        value={totals.delivered}
                        caption={t('of shipped')}
                        rate={tileRates.delivered}
                        accent="success"
                    />
                    <StatTile
                        label={countLabel('Cancelled')}
                        value={totals.cancelled}
                        caption={t('of assigned')}
                        rate={tileRates.cancelled}
                        accent="destructive"
                    />
                    <StatTile
                        label={t('Commission earned')}
                        value={money(commissionEarned)}
                    />
                </div>

                {/* Two charts rather than one with grouped bars: the two
                    metrics carry different targets (80% vs 90% by
                    default), and RateBarChart draws a single reference
                    line — sharing one would mis-state the threshold for
                    whichever metric didn't own it. */}
                <RateBarChart
                    title={t('My confirmation rate')}
                    total={rateTotals.confirmation}
                    totalPeriodDays={periodDays}
                    series={[
                        {
                            key: 'confirmation',
                            label: t('Confirmation rate'),
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
                    title={t('My delivery rate')}
                    total={rateTotals.delivery}
                    totalPeriodDays={periodDays}
                    series={[
                        {
                            key: 'delivery',
                            label: t('Delivery rate'),
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
