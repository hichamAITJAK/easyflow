import { useEffect, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { OrderDetail } from '@/components/orders/order-detail';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import type { AgentOption } from '@/pages/orders/columns';
import type { Order } from '@/types';

/**
 * The admin's full order detail — every field the trimmed datatable
 * columns leave out, plus event history, in one large dialog instead of a
 * horizontal-scroll-then-vertical-scroll hunt across the table. The row's
 * own data opens instantly; a background fetch to OrderController::show()
 * then fills in status_events (never eager-loaded on the list, since that
 * would bloat every page load for history nobody's looking at yet).
 */
export function OrderViewDialog({
    open,
    onOpenChange,
    order,
    onCreateShipment,
    agents,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
    onCreateShipment?: (order: Order) => void;
    agents?: AgentOption[];
}) {
    const { t } = useTranslation();

    const [detail, setDetail] = useState<Order | null>(null);
    const [loadingHistory, setLoadingHistory] = useState(false);

    const refetch = () => {
        if (!order) {
            return;
        }

        fetch(OrderController.show.url(order.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { order: Order } | null) => {
                if (data) {
                    setDetail(data.order);
                }
            });
    };

    useEffect(() => {
        if (!open || !order) {
            setDetail(null);

            return;
        }

        setDetail(order);
        setLoadingHistory(true);

        fetch(OrderController.show.url(order.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { order: Order } | null) => {
                if (data) {
                    setDetail(data.order);
                }
            })
            .finally(() => setLoadingHistory(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, order?.id]);

    if (!order) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('Order :reference', { reference: order.reference ?? `#${order.id}` })}
                    </DialogTitle>
                </DialogHeader>

                {detail ? (
                    <OrderDetail
                        order={detail}
                        onCreateShipment={onCreateShipment}
                        agents={agents}
                        onAgentAssigned={refetch}
                    />
                ) : (
                    <div className="space-y-3">
                        <Skeleton className="h-6 w-40" />
                        <Skeleton className="h-24 w-full" />
                        <Skeleton className="h-24 w-full" />
                    </div>
                )}
                {loadingHistory && detail && (
                    <p className="text-xs text-muted-foreground">
                        {t('Loading event history…')}
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
