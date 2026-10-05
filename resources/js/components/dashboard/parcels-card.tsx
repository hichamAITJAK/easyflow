import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    PackageCheck,
    RotateCcw,
    Truck,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as parcelsIndex } from '@/routes/parcels';

export type ParcelStages = {
    /** Registered or staged, not yet collected by the courier. */
    ready: number;
    /** With the courier, on the way to the customer. */
    shipped: number;
    delivered: number;
    /** Refused, on the way back, or back in the warehouse. */
    returned: number;
};

type StageKey = keyof ParcelStages;

const STAGES: {
    key: StageKey;
    label: string;
    hint: string;
    icon: LucideIcon;
    /** Icon tile + bar tint. Only the two outcomes carry colour. */
    tone: string;
    bar: string;
}[] = [
    {
        key: 'ready',
        label: 'Ready',
        hint: 'Waiting for the courier',
        icon: Warehouse,
        tone: 'bg-muted text-muted-foreground',
        bar: 'bg-muted-foreground/40',
    },
    {
        key: 'shipped',
        label: 'Shipped',
        hint: 'On the way to the customer',
        icon: Truck,
        tone: 'bg-primary/10 text-primary',
        bar: 'bg-primary',
    },
    {
        key: 'delivered',
        label: 'Delivered',
        hint: 'Received and paid',
        icon: PackageCheck,
        tone: 'bg-success/10 text-success',
        bar: 'bg-success',
    },
    {
        key: 'returned',
        label: 'Returned',
        hint: 'Refused or sent back',
        icon: RotateCcw,
        tone: 'bg-destructive/10 text-destructive',
        bar: 'bg-destructive',
    },
];

/**
 * Where the period's parcels are right now, as four stage rows. Each row
 * carries its count and its share of all parcels, with a thin bar so the
 * split reads at a glance without a chart.
 */
export function ParcelsCard({ stages }: { stages: ParcelStages }) {
    const { t } = useTranslation();

    const total = STAGES.reduce((sum, stage) => sum + stages[stage.key], 0);

    // "Shipped", "Delivered" and "Returned" are already translated as the
    // status of one order. Here they head a count of parcels, which other
    // languages word differently, so the stage labels get their own
    // entries and fall back to the plain English word when there is none.
    const stageLabel = (label: string): string => {
        const key = `${label} (parcels)`;
        const translated = t(key);

        return translated === key ? label : translated;
    };

    return (
        <Card className="flex h-full flex-col shadow-none">
            <CardHeader className="flex flex-wrap items-start justify-between gap-2 space-y-0">
                <div className="grid gap-1.5">
                    <CardTitle>{t('Parcels')}</CardTitle>
                    <CardDescription>
                        {total === 0
                            ? t('No parcels in this period.')
                            : total === 1
                              ? t(':count parcel in this period', {
                                    count: formatNumber(total),
                                })
                              : t(':count parcels in this period', {
                                    count: formatNumber(total),
                                })}
                    </CardDescription>
                </div>
                <Link
                    href={parcelsIndex()}
                    prefetch
                    className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    {t('View all')}
                    <ArrowRight aria-hidden className="size-3.5" />
                </Link>
            </CardHeader>

            <CardContent className="flex flex-1 flex-col">
                <ul className="flex flex-1 flex-col justify-between divide-y">
                    {STAGES.map((stage) => {
                        const count = stages[stage.key];
                        const share =
                            total > 0 ? Math.round((count / total) * 100) : 0;

                        return (
                            <li
                                key={stage.key}
                                className="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <span
                                    className={cn(
                                        'flex size-9 shrink-0 items-center justify-center rounded-md',
                                        stage.tone,
                                    )}
                                >
                                    <stage.icon
                                        aria-hidden
                                        className="size-4"
                                    />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="truncate text-sm font-medium">
                                            {stageLabel(stage.label)}
                                        </span>
                                        <span className="flex items-baseline gap-2 tabular-nums">
                                            <span className="text-lg leading-none font-semibold">
                                                {formatNumber(count)}
                                            </span>
                                            <span className="w-9 text-right text-xs text-muted-foreground">
                                                {share}%
                                            </span>
                                        </span>
                                    </div>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {t(stage.hint)}
                                    </p>
                                    <div
                                        aria-hidden
                                        className="mt-2 h-1 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            className={cn(
                                                'h-full rounded-full',
                                                stage.bar,
                                            )}
                                            style={{ width: `${share}%` }}
                                        />
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}
