import { useEffect, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format';
import { statusEventLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { Order } from '@/types';

/**
 * A parcel's status trail, read from the order_status_events audit rows
 * written on every transition. Status events are never eager-loaded on the
 * parcels list — that would cost a join per page for history nobody has
 * asked for yet — so this fetches OrderController::show() on open.
 *
 * The timeline mirrors OrderDetail's history block rather than inventing a
 * second visual language for the same data; only the framing differs, since
 * a parcel is one order viewed from the warehouse side.
 */
export function ParcelHistoryDialog({
    open,
    onOpenChange,
    order,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
}) {
    const { t } = useTranslation();

    // Keyed by order id rather than reset on close: clearing it in the
    // effect's early-return branch would be a synchronous setState inside an
    // effect, which cascades an extra render on every open/close. Reading
    // "is this detail the one for the open row?" derives the same thing for
    // free, and a reopened row paints its history instantly.
    const [detail, setDetail] = useState<{ id: number; order: Order } | null>(
        null,
    );
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open || !order) {
            return;
        }

        // Guards against a slow response for a previously-opened parcel
        // landing after the user has already switched rows.
        let active = true;

        // Marking the fetch in-flight is the one synchronous setState this
        // effect can't derive away — the request is the external system the
        // effect exists to drive. Same shape as OrderViewDialog.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        setFailed(false);

        fetch(OrderController.show.url(order.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { order: Order } | null) => {
                if (!active) {
                    return;
                }

                if (data) {
                    setDetail({ id: order.id, order: data.order });
                } else {
                    setFailed(true);
                }
            })
            .catch(() => {
                if (active) {
                    setFailed(true);
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });

        return () => {
            active = false;
        };
    }, [open, order]);

    // Only trust the cached detail when it belongs to the row now open —
    // otherwise the previous parcel's trail would flash before the fetch for
    // this one resolves.
    const current = order && detail?.id === order.id ? detail.order : null;

    // Oldest first, so the trail reads top-to-bottom in the order the parcel
    // actually moved. The endpoint returns newest first.
    const events = [...(current?.status_events ?? [])].reverse();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('Parcel history')}</DialogTitle>
                    <DialogDescription>
                        {order?.reference
                            ? t('Every recorded status change for :reference.', { reference: order.reference })
                            : t('Every recorded status change for this parcel.')}
                    </DialogDescription>
                </DialogHeader>

                {/* `!current` covers the frame between opening a fresh row and
                    the effect setting `loading` — without it that frame would
                    flash "No status changes recorded yet." */}
                {loading || (!current && !failed) ? (
                    <div className="space-y-4" aria-live="polite">
                        <span className="sr-only">{t('Loading parcel history…')}</span>
                        {[0, 1, 2].map((key) => (
                            <div key={key} className="flex gap-3">
                                <Skeleton className="mt-1 size-2.5 shrink-0 rounded-full" />
                                <div className="grid flex-1 gap-1.5">
                                    <Skeleton className="h-4 w-48" />
                                    <Skeleton className="h-3 w-32" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : failed ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {t('Couldn’t load this parcel’s history. Close and try again.')}
                    </p>
                ) : events.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {t('No status changes recorded yet.')}
                    </p>
                ) : (
                    <ol className="space-y-4">
                        {events.map((event, index) => {
                            const isCurrent = index === events.length - 1;

                            return (
                                <li key={event.id} className="flex gap-3">
                                    <div className="flex flex-col items-center">
                                        <span
                                            className={cn(
                                                'mt-1 shrink-0 rounded-full',
                                                isCurrent
                                                    ? 'size-2.5 bg-primary ring-4 ring-primary/15'
                                                    : 'size-2 border-2 border-muted-foreground/40 bg-background',
                                            )}
                                        />
                                        {index < events.length - 1 && (
                                            <div className="my-1 w-px flex-1 bg-border" />
                                        )}
                                    </div>
                                    <div className="grid flex-1 gap-0.5 pb-1 leading-tight">
                                        <span
                                            className={cn(
                                                'text-sm',
                                                isCurrent
                                                    ? 'font-semibold'
                                                    : 'font-medium text-muted-foreground',
                                            )}
                                        >
                                            {event.from_status
                                                ? `${t(statusEventLabel(event.from_status))} → ${t(statusEventLabel(event.to_status))}`
                                                : statusEventLabel(
                                                      event.to_status,
                                                  )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {event.created_at &&
                                                formatDateTime(
                                                    event.created_at,
                                                )}
                                            {/* Courier-driven transitions
                                                have no human actor. */}
                                            {event.changed_by_user
                                                ? ` · ${event.changed_by_user.name}`
                                                : t('· Courier')}
                                        </span>
                                        {event.note && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {event.note}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </DialogContent>
        </Dialog>
    );
}
