import { Head, router } from '@inertiajs/react';
import { Printer, ScanLine, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PILL_TABS_COUNT } from '@/components/pill-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useTranslation } from '@/hooks/use-translation';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import {
    index as fulfillmentIndex,
    local as fulfillmentLocal,
    tickets as fulfillmentTickets,
} from '@/routes/fulfillment';
import type { Order } from '@/types';

type Filter = 'to_prepare' | 'ready' | 'all';

type Parcel = Pick<
    Order,
    | 'id'
    | 'reference'
    | 'courier_tracking_number'
    | 'customer_name'
    | 'customer_phone'
    | 'customer_address'
    | 'customer_city'
    | 'total_amount'
    | 'delivery_status'
    | 'delivery_guy'
    | 'shipped_at'
> & { store?: { id: number; name: string } | null };

/**
 * Own delivery, warehouse side: the parcels going out with the business's
 * delivery guys. Print their labels, scan them out on the Scan tab, hand
 * the lot to the guy of that city.
 */
export default function FulfillmentLocal({
    filter,
    parcels,
    counts,
}: {
    filter: Filter;
    parcels: Parcel[];
    counts: { to_prepare: number; ready: number };
}) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const filters: { key: Filter; label: string; count?: number }[] = [
        { key: 'to_prepare', label: t('To prepare'), count: counts.to_prepare },
        { key: 'ready', label: t('Ready'), count: counts.ready },
        { key: 'all', label: t('All') },
    ];

    const allSelected =
        parcels.length > 0 &&
        parcels.every((parcel) => selected.has(parcel.id));

    const toggleAll = () =>
        setSelected(
            allSelected ? new Set() : new Set(parcels.map((p) => p.id)),
        );

    const toggle = (id: number) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    // Grouped by city so the warehouse works one delivery guy at a time.
    const byCity = useMemo(() => {
        const groups = new Map<string, Parcel[]>();

        for (const parcel of parcels) {
            const city =
                parcel.delivery_guy?.delivery_city ??
                parcel.customer_city ??
                '—';
            groups.set(city, [...(groups.get(city) ?? []), parcel]);
        }

        return [...groups.entries()];
    }, [parcels]);

    const printSelected = () => {
        const ids = [...selected].join(',');
        window.open(fulfillmentTickets({ query: { ids } }).url, '_blank');
    };

    return (
        <>
            <Head title={t('Local delivery')} />

            <div className="mx-auto w-full max-w-[720px] px-4 pt-6 pb-4 lg:pb-10">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('Local delivery')}
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t(
                                'Parcels going out with your own delivery guys. Print the labels, scan each parcel out, hand them over.',
                            )}
                        </p>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <a href={fulfillmentIndex().url}>
                            <ScanLine />
                            {t('Scan')}
                        </a>
                    </Button>
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex gap-1 rounded-lg border border-border bg-muted/40 p-1">
                        {filters.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        fulfillmentLocal({
                                            query: { status: item.key },
                                        }).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className={cn(
                                    'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium',
                                    filter === item.key
                                        ? 'bg-card text-foreground shadow-xs'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {item.label}
                                {item.count !== undefined && (
                                    <span className={PILL_TABS_COUNT}>
                                        {item.count}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>

                    <Button
                        size="sm"
                        disabled={selected.size === 0}
                        onClick={printSelected}
                    >
                        <Printer />
                        {t('Print tickets')}
                        {selected.size > 0 && ` (${selected.size})`}
                    </Button>
                </div>

                {parcels.length === 0 ? (
                    <Empty className="mt-4 border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>{t('No local parcels')}</EmptyTitle>
                            <EmptyDescription>
                                {t(
                                    'Parcels the confirmation team hands to a delivery guy will show up here.',
                                )}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="mt-4 space-y-5">
                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Checkbox
                                checked={allSelected}
                                onCheckedChange={toggleAll}
                            />
                            {t('Select all')}
                        </label>

                        {byCity.map(([city, group]) => (
                            <section key={city} className="space-y-2">
                                <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <Truck className="size-3.5" />
                                    {city}
                                    <span className={PILL_TABS_COUNT}>
                                        {group.length}
                                    </span>
                                </h2>
                                <div className="divide-y rounded-xl border bg-card">
                                    {group.map((parcel) => (
                                        <label
                                            key={parcel.id}
                                            className="flex cursor-pointer items-start gap-3 p-3"
                                        >
                                            <Checkbox
                                                className="mt-1"
                                                checked={selected.has(
                                                    parcel.id,
                                                )}
                                                onCheckedChange={() =>
                                                    toggle(parcel.id)
                                                }
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="truncate font-medium">
                                                        {parcel.customer_name}
                                                    </span>
                                                    <span className="shrink-0 font-mono text-sm font-semibold tabular-nums">
                                                        {parcel.total_amount}{' '}
                                                        MAD
                                                    </span>
                                                </div>
                                                <div className="truncate text-sm text-muted-foreground">
                                                    {parcel.customer_address}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                    <span className="font-mono">
                                                        {
                                                            parcel.courier_tracking_number
                                                        }
                                                    </span>
                                                    {parcel.store?.name && (
                                                        <span>
                                                            ·{' '}
                                                            {parcel.store.name}
                                                        </span>
                                                    )}
                                                    {parcel.delivery_guy && (
                                                        <span>
                                                            ·{' '}
                                                            {
                                                                parcel
                                                                    .delivery_guy
                                                                    .name
                                                            }
                                                        </span>
                                                    )}
                                                    {parcel.delivery_status && (
                                                        <Badge
                                                            variant="outline"
                                                            className={cn(
                                                                statusChipClass,
                                                                deliveryStatusColors[
                                                                    parcel
                                                                        .delivery_status
                                                                ],
                                                            )}
                                                        >
                                                            {t(
                                                                deliveryStatusLabels[
                                                                    parcel
                                                                        .delivery_status
                                                                ],
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
