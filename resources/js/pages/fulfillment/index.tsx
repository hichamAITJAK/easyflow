import { Head } from '@inertiajs/react';
import { Package, ScanLine, Undo2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActivityList } from '@/components/fulfillment/activity-list';
import { ParcelCard } from '@/components/fulfillment/parcel-card';
import { ScannerView } from '@/components/fulfillment/scanner-view';
import { PILL_TABS_LIST, PILL_TABS_TRIGGER } from '@/components/pill-tabs';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import { fulfillmentApi, FulfillmentApiError } from '@/lib/fulfillment-api';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    FulfillmentActivityEvent,
    FulfillmentScanResult,
    FulfillmentStockChange,
    FulfillmentSummary,
} from '@/types/fulfillment';

/** A counter tile narrows the Today log to the scans of its kind. */
type TileFilter = 'prepare' | 'returns';

const TILE_STATUS: Record<TileFilter, string> = {
    prepare: 'ready_for_pickup',
    returns: 'return_received',
};

/**
 * One line per product whose stock moved, e.g. "Serum · Black: 12 → 10 (−2)".
 * Says so plainly when nothing tracked moved.
 */
function describeStock(changes: FulfillmentStockChange[], t: Translator) {
    if (changes.length === 0) {
        return t('No tracked stock changed');
    }

    return (
        <ul className="grid gap-0.5">
            {changes.map((c, index) => {
                const name = c.variant
                    ? `${c.product} · ${c.variant}`
                    : c.product;
                const sign = c.change > 0 ? '+' : c.change < 0 ? '−' : '';

                return (
                    <li key={index} className="tabular-nums">
                        {name}: {c.before} → {c.after} ({sign}
                        {Math.abs(c.change)})
                    </li>
                );
            })}
        </ul>
    );
}

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

    // A packing run is minutes of reading the screen without touching it;
    // letting the phone sleep between parcels would mean unlocking it for
    // every single scan.
    useWakeLock();

    const [tab, setTab] = useState('scan');
    const [summary, setSummary] = useState(initialSummary);
    const [result, setResult] = useState<FulfillmentScanResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const [events, setEvents] = useState<FulfillmentActivityEvent[]>([]);
    const [activityLoading, setActivityLoading] = useState(false);
    const [undoingId, setUndoingId] = useState<number | null>(null);
    const [filter, setFilter] = useState<TileFilter | null>(null);

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
            const { order, stock_changes } = await fulfillmentApi.confirm(
                result.order.id,
            );
            buzz('ok');
            toast.success(
                `${order.courier_tracking_number ?? 'Parcel'} updated`,
                { description: describeStock(stock_changes, t) },
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
                const { stock_changes } = await fulfillmentApi.undo(eventId);
                toast.success(t('Scan undone'), {
                    description: describeStock(stock_changes, t),
                });
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

    // A tile toggles its filter and opens the log it filters; tapping the
    // active tile again clears it.
    const handleTile = (kind: TileFilter) => {
        const next = filter === kind ? null : kind;
        setFilter(next);

        if (next && tab !== 'activity') {
            handleTabChange('activity');
        }
    };

    const visibleEvents = filter
        ? events.filter((event) => event.to_status === TILE_STATUS[filter])
        : events;

    return (
        <>
            <Head title={t('Fulfilment')} />

            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-4 lg:pb-10">
                <div className="grid auto-rows-fr grid-cols-2 gap-4">
                    <Tile
                        icon={Package}
                        label={t('To prepare')}
                        value={summary.ready_to_prepare}
                        color="#EFA22C"
                        text="#A87110"
                        active={filter === 'prepare'}
                        onClick={() => handleTile('prepare')}
                    />
                    <Tile
                        icon={Undo2}
                        label={t('Returns pending')}
                        value={summary.returns_pending}
                        color="#D92D20"
                        text="#B3281D"
                        active={filter === 'returns'}
                        onClick={() => handleTile('returns')}
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
                            'mt-5 grid w-full max-w-none grid-cols-2 border border-border bg-muted/40',
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
                    </TabsList>

                    <TabsContent value="scan">
                        <ScannerView
                            onScan={handleScan}
                            busy={busy || confirming}
                        />

                        {result && (
                            <div className="mt-4">
                                <ParcelCard
                                    order={result.order}
                                    action={result.action}
                                    onConfirm={handleConfirm}
                                    confirming={confirming}
                                />
                            </div>
                        )}
                    </TabsContent>

                    <TabsContent value="activity">
                        {filter && (
                            <p className="mt-4 text-xs text-muted-foreground">
                                {filter === 'prepare'
                                    ? t('Showing parcels scanned out.')
                                    : t('Showing returns received.')}{' '}
                                <button
                                    type="button"
                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                    onClick={() => setFilter(null)}
                                >
                                    {t('Show all')}
                                </button>
                            </p>
                        )}
                        <ActivityList
                            events={visibleEvents}
                            loading={activityLoading}
                            onUndo={handleUndo}
                            undoingId={undoingId}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

/** A counter as a tappable tile: tinted icon, mono figure, coloured label. */
function Tile({
    icon: Icon,
    label,
    value,
    color,
    text,
    active,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    color: string;
    text: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex items-center gap-3.5 rounded-xl border bg-card p-5 text-left shadow-xs transition-colors',
                !active && 'border-border hover:bg-muted/30',
            )}
            style={
                active
                    ? { borderColor: color, background: `${color}0a` }
                    : undefined
            }
        >
            <span
                className="flex size-11 shrink-0 items-center justify-center rounded-full"
                style={{ background: `${color}1a`, color: text }}
            >
                <Icon className="size-5" />
            </span>
            <span className="min-w-0">
                <span className="block font-mono text-[28px] leading-none font-semibold tabular-nums">
                    {value}
                </span>
                <span
                    className="mt-1.5 block text-sm leading-tight font-medium"
                    style={{ color: text }}
                >
                    {label}
                </span>
            </span>
        </button>
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
