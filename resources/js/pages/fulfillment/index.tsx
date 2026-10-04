import { Head } from '@inertiajs/react';
import { PackageSearch, RotateCcw, ScanLine } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActivityList } from '@/components/fulfillment/activity-list';
import { ParcelCard } from '@/components/fulfillment/parcel-card';
import { ScannerView } from '@/components/fulfillment/scanner-view';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import FulfillmentLayout from '@/layouts/fulfillment-layout';
import { fulfillmentApi, FulfillmentApiError } from '@/lib/fulfillment-api';
import type {
    FulfillmentActivityEvent,
    FulfillmentScanResult,
    FulfillmentSummary,
} from '@/types/fulfillment';

/**
 * The fulfilment agent's entire workspace (UC-16/UC-17).
 *
 * Mirrors the mobile navigator deliberately: two counters, a camera, a
 * preview, one button, plus a read-only activity log. There is no order
 * list and no status picker — the scanned parcel's current status is the
 * only thing that decides what happens next, which removes the "which mode
 * am I in?" mistake under fast, repetitive work.
 */
export default function FulfillmentIndex({
    summary: initialSummary,
}: {
    summary: FulfillmentSummary;
}) {
    const { t } = useTranslation();

    const [tab, setTab] = useState('scan');
    const [summary, setSummary] = useState(initialSummary);
    const [result, setResult] = useState<FulfillmentScanResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const [events, setEvents] = useState<FulfillmentActivityEvent[]>([]);
    const [activityLoading, setActivityLoading] = useState(false);
    const [undoingId, setUndoingId] = useState<number | null>(null);

    // Guards against a second scan landing while the first is still in
    // flight — a bench scanner can fire twice on one pass of the label.
    const inFlight = useRef(false);

    const refreshSummary = useCallback(async () => {
        try {
            setSummary(await fulfillmentApi.summary());
        } catch {
            // The counters are context, not the task. A failed refresh must
            // never interrupt scanning with an error the agent cannot act on.
        }
    }, []);

    const loadActivity = useCallback(async () => {
        setActivityLoading(true);

        try {
            setEvents((await fulfillmentApi.activity()).events);
        } catch (error) {
            toast.error(errorMessage(error));
        } finally {
            setActivityLoading(false);
        }
    }, []);

    const handleScan = useCallback(async (value: string) => {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy(true);

        try {
            const scanned = await fulfillmentApi.scan(value);
            setResult(scanned);
            buzz(scanned.action ? 'ok' : 'warn');
        } catch (error) {
            setResult(null);
            buzz('error');
            toast.error(errorMessage(error));
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }, []);

    const handleConfirm = useCallback(async () => {
        if (!result) {
            return;
        }

        setConfirming(true);

        try {
            const { order } = await fulfillmentApi.confirm(result.order.id);
            buzz('ok');
            toast.success(
                `${order.courier_tracking_number ?? 'Parcel'} updated`,
            );

            // Clear the card immediately so the next parcel starts from a
            // blank screen — leaving the previous one up is how a parcel
            // gets scanned twice.
            setResult(null);
            void refreshSummary();
        } catch (error) {
            buzz('error');
            toast.error(errorMessage(error));
        } finally {
            setConfirming(false);
        }
    }, [result, refreshSummary]);

    const handleUndo = useCallback(
        async (eventId: number) => {
            setUndoingId(eventId);

            try {
                await fulfillmentApi.undo(eventId);
                toast.success(t('Scan undone'));
                await Promise.all([loadActivity(), refreshSummary()]);
            } catch (error) {
                toast.error(errorMessage(error));
                // The window may have closed or the parcel moved on; reload
                // so the row stops offering an undo the server will refuse.
                void loadActivity();
            } finally {
                setUndoingId(null);
            }
        },
        [loadActivity, refreshSummary],
    );

    // Driven by the tab change itself rather than an effect watching `tab`:
    // opening the log is a user action, and reacting to the resulting state
    // instead would fetch again on every unrelated re-render of this page.
    const handleTabChange = useCallback(
        (next: string) => {
            setTab(next);

            if (next === 'activity') {
                void loadActivity();
            }
        },
        [loadActivity],
    );

    return (
        <FulfillmentLayout>
            <Head title={t('Fulfilment')} />

            <div className="mx-auto w-full max-w-lg space-y-4 p-4">
                <div className="grid grid-cols-2 gap-3">
                    <Counter
                        icon={PackageSearch}
                        label={t('To prepare')}
                        value={summary.ready_to_prepare}
                    />
                    <Counter
                        icon={RotateCcw}
                        label={t('Returns pending')}
                        value={summary.returns_pending}
                    />
                </div>

                <Tabs value={tab} onValueChange={handleTabChange}>
                    <TabsList className="grid w-full grid-cols-2">
                        <TabsTrigger value="scan">
                            <ScanLine className="size-4" />
                            {t('Scan')}
                        </TabsTrigger>
                        <TabsTrigger value="activity">{t('Today')}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="scan" className="mt-4 space-y-4">
                        <ScannerView
                            onScan={handleScan}
                            busy={busy || confirming}
                        />

                        {result && (
                            <ParcelCard
                                order={result.order}
                                action={result.action}
                                onConfirm={handleConfirm}
                                confirming={confirming}
                            />
                        )}
                    </TabsContent>

                    <TabsContent value="activity" className="mt-4">
                        <ActivityList
                            events={events}
                            loading={activityLoading}
                            onUndo={handleUndo}
                            undoingId={undoingId}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </FulfillmentLayout>
    );
}

function Counter({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof PackageSearch;
    label: string;
    value: number;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-3 p-4">
                <Icon className="size-5 shrink-0 text-muted-foreground" />
                <div className="min-w-0">
                    <p className="text-2xl leading-none font-semibold tabular-nums">
                        {value}
                    </p>
                    <p className="mt-1 truncate text-xs text-muted-foreground">
                        {label}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function errorMessage(error: unknown): string {
    return error instanceof FulfillmentApiError
        ? error.message
        : 'Something went wrong. Try again.';
}

/**
 * Haptic confirmation. In a warehouse the phone is often held at arm's
 * length against a parcel, where a buzz registers before the screen does.
 * Unsupported on iOS Safari, which ignores the call — the visual toast
 * carries the same information either way.
 */
function buzz(kind: 'ok' | 'warn' | 'error') {
    if (typeof navigator.vibrate !== 'function') {
        return;
    }

    navigator.vibrate(
        kind === 'ok' ? 40 : kind === 'warn' ? [30, 60, 30] : [80, 50, 80],
    );
}
