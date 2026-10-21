import { Head } from '@inertiajs/react';
import {
    Banknote,
    MapPin,
    PackageCheck,
    ScanLine,
    Truck,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DeliveryActivityList } from '@/components/delivery/activity-list';
import { ParcelActionCard } from '@/components/delivery/parcel-action-card';
import type { ApplyPayload } from '@/components/delivery/parcel-action-card';
import { ScannerView } from '@/components/fulfillment/scanner-view';
import { PILL_TABS_LIST, PILL_TABS_TRIGGER } from '@/components/pill-tabs';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import { deliveryApi } from '@/lib/delivery-api';
import { formatDateTime } from '@/lib/format';
import { FulfillmentApiError } from '@/lib/fulfillment-api';
import { deliveryStatusLabels } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type {
    DeliveryActivityEvent,
    DeliveryCash,
    DeliveryScanResult,
    DeliverySummary,
} from '@/types/delivery-guy';

function errorMessage(error: unknown): string {
    return error instanceof FulfillmentApiError
        ? error.message
        : 'Something went wrong. Try again.';
}

/**
 * The delivery guy's workspace, built like the warehouse one: counters,
 * a scanner, the scanned parcel with what can be done to it, today's log
 * with a short undo, and the cash he is holding. The scanned label's
 * current status decides the buttons — he never picks a status from a
 * list, so a parcel can't be marked delivered before it was taken.
 */
export default function DeliveryIndex({
    summary: initialSummary,
}: {
    summary: DeliverySummary;
}) {
    const { t } = useTranslation();

    // On the road the phone is read between parcels, not touched; letting
    // it sleep would mean unlocking it at every door.
    useWakeLock();

    const [tab, setTab] = useState('scan');
    const [summary, setSummary] = useState(initialSummary);
    const [result, setResult] = useState<DeliveryScanResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [applying, setApplying] = useState(false);

    const [events, setEvents] = useState<DeliveryActivityEvent[]>([]);
    const [activityLoading, setActivityLoading] = useState(false);
    const [undoingId, setUndoingId] = useState<number | null>(null);

    const [cash, setCash] = useState<DeliveryCash | null>(null);
    const [cashLoading, setCashLoading] = useState(false);

    const inFlight = useRef(false);

    const refreshSummary = useCallback(async () => {
        try {
            setSummary(await deliveryApi.summary());
        } catch {
            // The counters are a courtesy; the next action refreshes them.
        }
    }, []);

    const loadActivity = useCallback(async () => {
        setActivityLoading(true);

        try {
            const { events } = await deliveryApi.activity();
            setEvents(events);
        } catch (error) {
            toast.error(errorMessage(error));
        } finally {
            setActivityLoading(false);
        }
    }, []);

    const loadCash = useCallback(async () => {
        setCashLoading(true);

        try {
            setCash(await deliveryApi.cash());
        } catch (error) {
            toast.error(errorMessage(error));
        } finally {
            setCashLoading(false);
        }
    }, []);

    const handleScan = useCallback(async (value: string) => {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy(true);

        try {
            setResult(await deliveryApi.scan(value));
        } catch (error) {
            setResult(null);
            toast.error(errorMessage(error));
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }, []);

    const handleApply = useCallback(
        async (payload: ApplyPayload) => {
            if (!result) {
                return;
            }

            setApplying(true);

            try {
                const response = await deliveryApi.apply({
                    order_id: result.order.id,
                    ...payload,
                });
                setSummary(response.summary);

                toast.success(
                    `${response.order.courier_tracking_number ?? t('Parcel')} · ${t(
                        deliveryStatusLabels[
                            response.order.delivery_status ?? 'out_for_delivery'
                        ],
                    )}`,
                );

                // A parcel just taken stays on screen: the next tap on it is
                // usually "delivered" at the door. Anything final clears it
                // so the next scan starts blank.
                setResult(
                    response.order.delivery_status === 'out_for_delivery'
                        ? { order: response.order, actions: response.actions }
                        : null,
                );
            } catch (error) {
                toast.error(errorMessage(error));
            } finally {
                setApplying(false);
            }
        },
        [result, t],
    );

    const handleUndo = useCallback(
        async (eventId: number) => {
            setUndoingId(eventId);

            try {
                const { summary } = await deliveryApi.undo(eventId);
                setSummary(summary);
                toast.success(t('Undone'));
                await loadActivity();
            } catch (error) {
                toast.error(errorMessage(error));
                void loadActivity();
            } finally {
                setUndoingId(null);
            }
        },
        [loadActivity, t],
    );

    const handleTabChange = useCallback(
        (next: string) => {
            setTab(next);

            if (next === 'activity') {
                void loadActivity();
            } else if (next === 'cash') {
                void loadCash();
                void refreshSummary();
            }
        },
        [loadActivity, loadCash, refreshSummary],
    );

    return (
        <>
            <Head title={t('Delivery')} />

            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-4 lg:pb-10">
                <p className="mb-3 flex items-center gap-1 text-sm text-muted-foreground">
                    <MapPin className="size-3.5" />
                    {summary.city ?? t('No city set')}
                </p>

                <div className="grid grid-cols-2 gap-3">
                    <Tile
                        icon={PackageCheck}
                        label={t('To collect')}
                        value={String(summary.to_collect)}
                        color="#EFA22C"
                        text="#A87110"
                    />
                    <Tile
                        icon={Truck}
                        label={t('On the road')}
                        value={String(summary.on_road)}
                        color="#468FA5"
                        text="#2E7389"
                    />
                    <Tile
                        icon={PackageCheck}
                        label={t('Delivered today')}
                        value={String(summary.delivered_today)}
                        color="#16A08E"
                        text="#0C7D6F"
                    />
                    <Tile
                        icon={Banknote}
                        label={t('Cash owed')}
                        value={`${summary.cash_owed.toFixed(0)} MAD`}
                        color="#49183C"
                        text="#49183C"
                    />
                </div>

                <Tabs
                    value={tab}
                    onValueChange={handleTabChange}
                    className="gap-0"
                >
                    <TabsList
                        className={cn(
                            PILL_TABS_LIST,
                            'mt-5 grid w-full max-w-none grid-cols-3 border border-border bg-muted/40',
                        )}
                    >
                        <TabsTrigger
                            value="scan"
                            className={cn(PILL_TABS_TRIGGER, 'h-9 py-0')}
                        >
                            <ScanLine className="size-4" />
                            {t('Scan')}
                        </TabsTrigger>
                        <TabsTrigger
                            value="activity"
                            className={cn(PILL_TABS_TRIGGER, 'h-9 py-0')}
                        >
                            {t('Today')}
                        </TabsTrigger>
                        <TabsTrigger
                            value="cash"
                            className={cn(PILL_TABS_TRIGGER, 'h-9 py-0')}
                        >
                            <Wallet className="size-4" />
                            {t('Cash')}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="scan">
                        <ScannerView
                            onScan={handleScan}
                            busy={busy || applying}
                            autoFocus={false}
                        />

                        {result && (
                            <div className="mt-4">
                                <ParcelActionCard
                                    order={result.order}
                                    actions={result.actions}
                                    onApply={handleApply}
                                    applying={applying}
                                />
                            </div>
                        )}
                    </TabsContent>

                    <TabsContent value="activity">
                        <DeliveryActivityList
                            events={events}
                            loading={activityLoading}
                            onUndo={handleUndo}
                            undoingId={undoingId}
                        />
                    </TabsContent>

                    <TabsContent value="cash">
                        <div className="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                            <div className="flex items-end justify-between gap-3 border-b border-border p-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        {t('Cash to hand over')}
                                    </p>
                                    <p className="font-mono text-3xl leading-none font-semibold tabular-nums">
                                        {cashLoading
                                            ? '…'
                                            : `${(cash?.owed ?? summary.cash_owed).toFixed(2)} MAD`}
                                    </p>
                                </div>
                                {cash?.last_settlement && (
                                    <p className="text-right text-xs text-muted-foreground">
                                        {t('Last hand-over')}
                                        <br />
                                        <span className="font-mono tabular-nums">
                                            {Number(
                                                cash.last_settlement.amount,
                                            ).toFixed(0)}{' '}
                                            MAD
                                        </span>{' '}
                                        ·{' '}
                                        {formatDateTime(
                                            cash.last_settlement.created_at,
                                        )}
                                    </p>
                                )}
                            </div>

                            {cash && cash.carried !== 0 && (
                                <div className="flex items-center gap-3 border-b border-border bg-muted/30 p-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {cash.carried > 0
                                                ? t(
                                                      'Left from your last hand-over',
                                                  )
                                                : t(
                                                      'Paid ahead at your last hand-over',
                                                  )}
                                        </p>
                                    </div>
                                    <span className="font-mono text-sm font-semibold tabular-nums">
                                        {cash.carried.toFixed(2)} MAD
                                    </span>
                                </div>
                            )}

                            {cash &&
                            cash.parcels.length === 0 &&
                            cash.carried === 0 ? (
                                <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                    {t('Nothing to hand over.')}
                                </div>
                            ) : (
                                cash?.parcels.map((parcel) => (
                                    <div
                                        key={parcel.id}
                                        className="flex items-center gap-3 border-b border-border p-4 last:border-0"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {parcel.customer_name}
                                            </p>
                                            <p className="truncate font-mono text-xs text-muted-foreground">
                                                {parcel.tracking_number ??
                                                    parcel.reference}
                                                {parcel.customer_city &&
                                                    ` · ${parcel.customer_city}`}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <span className="font-mono text-sm font-semibold tabular-nums">
                                                {parcel.amount} MAD
                                            </span>
                                            {Number(parcel.pay) > 0 && (
                                                <p className="font-mono text-xs text-muted-foreground tabular-nums">
                                                    {parcel.total} −{' '}
                                                    {parcel.pay}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t(
                                'Amounts are after your pay per parcel. Hand the cash to your manager; anything missing stays on your balance.',
                            )}
                        </p>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

function Tile({
    icon: Icon,
    label,
    value,
    color,
    text,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    color: string;
    text: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-border bg-card p-4 shadow-xs">
            <span
                className="flex size-10 shrink-0 items-center justify-center rounded-full"
                style={{ background: `${color}1a`, color: text }}
            >
                <Icon className="size-5" />
            </span>
            <span className="min-w-0">
                <span className="block truncate font-mono text-[22px] leading-none font-semibold tabular-nums">
                    {value}
                </span>
                <span className="mt-1 block truncate text-xs text-muted-foreground">
                    {label}
                </span>
            </span>
        </div>
    );
}
