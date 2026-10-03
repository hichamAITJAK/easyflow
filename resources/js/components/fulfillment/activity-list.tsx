import { CheckCircle2, PackageCheck, Undo2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import { deliveryStatusLabels } from '@/lib/order-status';
import type { FulfillmentActivityEvent } from '@/types/fulfillment';
import type { OrderDeliveryStatus } from '@/types/order';

/**
 * Today's committed scans, newest first.
 *
 * Undo is offered only while the server still allows it (`can_undo`, a
 * 5-minute window). The flag is computed server-side and not recalculated
 * here: a phone left open on the packing bench would otherwise drift out
 * of sync with the server clock and offer an undo that is already refused.
 */
export function ActivityList({
    events,
    loading,
    onUndo,
    undoingId,
}: {
    events: FulfillmentActivityEvent[];
    loading: boolean;
    onUndo: (eventId: number) => void;
    undoingId: number | null;
}) {
    const { t } = useTranslation();

    if (loading) {
        return (
            <div className="space-y-2">
                {[0, 1, 2].map((key) => (
                    <Skeleton key={key} className="h-20 w-full rounded-xl" />
                ))}
            </div>
        );
    }

    if (events.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <PackageCheck />
                    </EmptyMedia>
                    <EmptyTitle>{t('No scans yet today')}</EmptyTitle>
                    <EmptyDescription>
                        {t('Parcels you scan will appear here so you can check or undo them.')}
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <ul className="space-y-2">
            {events.map((event) => (
                <li
                    key={event.id}
                    className="flex items-center gap-3 rounded-xl border p-3"
                >
                    <CheckCircle2 className="size-5 shrink-0 text-emerald-600 dark:text-emerald-500" />

                    <div className="min-w-0 flex-1">
                        <p className="truncate font-mono text-sm font-medium">
                            {event.tracking_number ?? `Order #${event.order_id}`}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {t(deliveryStatusLabels[event.to_status as OrderDeliveryStatus]) ??
                                event.to_status}
                            {event.customer_name ? ` · ${event.customer_name}` : ''}
                        </p>
                    </div>

                    <time
                        dateTime={event.created_at}
                        className="shrink-0 text-xs tabular-nums text-muted-foreground"
                    >
                        {new Date(event.created_at).toLocaleTimeString(undefined, {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </time>

                    {event.can_undo && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="shrink-0"
                            onClick={() => onUndo(event.id)}
                            disabled={undoingId !== null}
                        >
                            <Undo2 className="size-4" />
                            <span className="sr-only">{t('Undo this scan')}</span>
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
