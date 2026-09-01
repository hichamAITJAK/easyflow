import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type Point = { date: string; count: number };

const dayLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', { weekday: 'short' });

const fullDateLabel = (iso: string) =>
    formatDate(iso + 'T00:00:00', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

/**
 * A single-series bar chart of orders assigned per day. No legend (single
 * series — the card title already names it); per-bar hover tooltip is the
 * hit target, matching the rest of the row.
 */
export function OrdersBarChart({ data }: { data: Point[] }) {
    const max = Math.max(1, ...data.map((point) => point.count));
    const allZero = data.every((point) => point.count === 0);

    return (
        <div className="w-full">
            <div className="flex h-40 items-end gap-1.5 border-b border-border pb-0">
                {data.map((point) => {
                    const heightPct = (point.count / max) * 100;

                    return (
                        <div
                            key={point.date}
                            className="group relative flex h-full flex-1 items-end justify-center"
                        >
                            {/* Tooltip */}
                            <div className="pointer-events-none absolute bottom-full z-10 mb-2 hidden -translate-x-1/2 flex-col items-center whitespace-nowrap rounded-md border bg-popover px-2 py-1 text-xs shadow-md group-hover:flex group-focus-visible:flex">
                                <span className="font-semibold text-popover-foreground tabular-nums">
                                    {point.count}{' '}
                                    {point.count === 1 ? 'order' : 'orders'}
                                </span>
                                <span className="text-muted-foreground">
                                    {fullDateLabel(point.date)}
                                </span>
                            </div>

                            {/* Bar */}
                            <div
                                tabIndex={0}
                                className={cn(
                                    'w-full max-w-6 rounded-t-[4px] bg-primary/85 transition-colors group-hover:bg-primary group-focus-visible:bg-primary outline-none',
                                    point.count === 0 && 'bg-muted',
                                )}
                                style={{
                                    height: point.count === 0 ? 2 : `${Math.max(heightPct, 4)}%`,
                                }}
                            />
                        </div>
                    );
                })}
            </div>

            <div className="mt-2 flex gap-1.5">
                {data.map((point, index) => (
                    <div
                        key={point.date}
                        className="flex-1 text-center text-[11px] text-muted-foreground"
                    >
                        {index % 2 === 0 ? dayLabel(point.date) : ''}
                    </div>
                ))}
            </div>

            {allZero && (
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    No orders assigned in the last 14 days.
                </p>
            )}
        </div>
    );
}
