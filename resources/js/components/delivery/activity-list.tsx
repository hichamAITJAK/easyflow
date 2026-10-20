import { Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { OrderDeliveryStatus } from '@/types';
import type { DeliveryActivityEvent } from '@/types/delivery-guy';

/**
 * Today's changes the delivery guy made, newest first. Undo is offered
 * only while the server still allows it (`can_undo`): a short window, and
 * never for a delivery, where cash changed hands.
 */
export function DeliveryActivityList({
    events,
    loading,
    onUndo,
    undoingId,
}: {
    events: DeliveryActivityEvent[];
    loading: boolean;
    onUndo: (eventId: number) => void;
    undoingId: number | null;
}) {
    const { t } = useTranslation();

    if (loading) {
        return (
            <div className="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                {[0, 1, 2].map((key) => (
                    <div
                        key={key}
                        className="flex items-center gap-3 border-b border-border p-4 last:border-0"
                    >
                        <div className="flex-1 space-y-2">
                            <Skeleton className="h-4 w-40" />
                            <Skeleton className="h-3 w-56" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            {events.length === 0 ? (
                <div className="px-4 py-12 text-center text-sm text-muted-foreground">
                    {t('Nothing yet today.')}
                </div>
            ) : (
                events.map((event) => {
                    const status = event.to_status as OrderDeliveryStatus;
                    const details = [
                        event.customer_name,
                        event.customer_city,
                        event.note,
                    ].filter(Boolean);

                    return (
                        <div
                            key={event.id}
                            className="flex items-center gap-3 border-b border-border p-4 last:border-0"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex min-w-0 items-center gap-2">
                                    <span
                                        className="truncate font-mono text-sm font-medium"
                                        title={
                                            event.tracking_number ?? undefined
                                        }
                                    >
                                        {event.reference ??
                                            event.tracking_number ??
                                            `#${event.order_id}`}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            statusChipClass,
                                            'shrink-0',
                                            deliveryStatusColors[status] ?? '',
                                        )}
                                    >
                                        {t(
                                            deliveryStatusLabels[status] ??
                                                status,
                                        )}
                                    </Badge>
                                </div>
                                {details.length > 0 && (
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {details.join(' · ')}
                                    </p>
                                )}
                            </div>

                            {event.to_status === 'delivered' &&
                                event.amount && (
                                    <span className="shrink-0 font-mono text-sm font-semibold text-[#0C7D6F] tabular-nums">
                                        +{event.amount}
                                    </span>
                                )}

                            <time
                                dateTime={event.created_at}
                                className="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                            >
                                {new Date(event.created_at).toLocaleTimeString(
                                    undefined,
                                    { hour: '2-digit', minute: '2-digit' },
                                )}
                            </time>

                            {event.can_undo && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 shrink-0 text-muted-foreground"
                                    onClick={() => onUndo(event.id)}
                                    disabled={undoingId !== null}
                                    title={t('Undo')}
                                >
                                    <Undo2 className="size-4" />
                                    <span className="sr-only">{t('Undo')}</span>
                                </Button>
                            )}
                        </div>
                    );
                })
            )}
        </div>
    );
}
