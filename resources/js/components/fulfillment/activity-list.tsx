import { Inbox, Truck, Undo2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import type { FulfillmentActivityEvent } from '@/types/fulfillment';

/** Direction is carried by colour: teal out to the courier, red back in. */
const DIRECTIONS = {
    ready_for_pickup: {
        label: 'Scanned out',
        icon: Truck,
        tint: 'bg-[#16A08E]/10 text-[#0C7D6F] dark:text-[#4CC7B4]',
    },
    return_received: {
        label: 'Return received',
        icon: Inbox,
        tint: 'bg-[#D92D20]/10 text-[#B3281D] dark:text-[#F0766C]',
    },
} as const;

/**
 * Today's committed scans, newest first, as one card of rows.
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
            <div className="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                {[0, 1, 2].map((key) => (
                    <div
                        key={key}
                        className="flex items-center gap-3 border-b border-border p-4 last:border-0"
                    >
                        <Skeleton className="size-9 rounded-full" />
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
        <>
            <div className="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                {events.length === 0 ? (
                    <div className="px-4 py-12 text-center text-sm text-muted-foreground">
                        {t('Nothing scanned yet today.')}
                    </div>
                ) : (
                    events.map((event) => {
                        const dir =
                            DIRECTIONS[
                                event.to_status as keyof typeof DIRECTIONS
                            ] ?? DIRECTIONS.ready_for_pickup;
                        const Icon = dir.icon;
                        const details = [
                            event.customer_name,
                            event.customer_city,
                            event.courier,
                        ].filter(Boolean);

                        return (
                            <div
                                key={event.id}
                                className="flex items-center gap-3 border-b border-border p-4 last:border-0"
                            >
                                <span
                                    className={`flex size-9 shrink-0 items-center justify-center rounded-full ${dir.tint}`}
                                >
                                    <Icon className="size-[18px]" />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span
                                            className="truncate font-mono text-sm font-medium"
                                            title={
                                                event.tracking_number ??
                                                undefined
                                            }
                                        >
                                            {event.reference ??
                                                event.tracking_number ??
                                                `#${event.order_id}`}
                                        </span>
                                        <span
                                            className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ${dir.tint}`}
                                        >
                                            {t(dir.label)}
                                        </span>
                                    </div>
                                    {details.length > 0 && (
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                            {details.join(' · ')}
                                        </p>
                                    )}
                                </div>

                                <time
                                    dateTime={event.created_at}
                                    className="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                >
                                    {new Date(
                                        event.created_at,
                                    ).toLocaleTimeString(undefined, {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </time>

                                {event.can_undo && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 shrink-0 text-muted-foreground"
                                        onClick={() => onUndo(event.id)}
                                        disabled={undoingId !== null}
                                        title={t('Undo this scan')}
                                    >
                                        <Undo2 className="size-4" />
                                        <span className="sr-only">
                                            {t('Undo this scan')}
                                        </span>
                                    </Button>
                                )}
                            </div>
                        );
                    })
                )}
            </div>
            <p className="mt-3 text-xs text-muted-foreground">
                {t(
                    events.length === 1
                        ? ':count scan today'
                        : ':count scans today',
                    { count: events.length },
                )}
            </p>
        </>
    );
}
