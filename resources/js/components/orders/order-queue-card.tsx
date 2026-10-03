import { AlertTriangle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { formatRelativeTime } from '@/lib/format';
import {
    confirmationStatusColors,
    confirmationStatusLabels,
    deliveryStatusColors,
    deliveryStatusLabels,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { Order } from '@/types';

/**
 * A single row in the confirmation agent's queue (left column of the
 * call-center layout) — compact enough to scan a long list at a glance:
 * name, phone, status, time. Full detail lives in the right-hand pane once
 * selected, not on the card itself.
 */
export function OrderQueueCard({
    id,
    order,
    selected,
    onSelect,
}: {
    /** Referenced by the list's `aria-activedescendant`. */
    id?: string;
    order: Order;
    selected: boolean;
    onSelect: () => void;
}) {
    const { t } = useTranslation();

    const status = order.delivery_status
        ? t(deliveryStatusLabels[order.delivery_status])
        : t(confirmationStatusLabels[order.confirmation_status]);
    const statusColor = order.delivery_status
        ? deliveryStatusColors[order.delivery_status]
        : confirmationStatusColors[order.confirmation_status];

    return (
        <Card
            id={id}
            // `option` inside the list's `listbox`, so arrow-key selection is
            // announced. `aria-selected` is the contract screen readers read
            // for this; `aria-current` was doing selection duty it doesn't own.
            role="option"
            aria-selected={selected}
            tabIndex={-1}
            onClick={onSelect}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect();
                }
            }}
            size="sm"
            className={cn(
                'cursor-pointer p-3 shadow ring-border transition-colors focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                selected
                    ? 'ring-2 ring-primary bg-primary/5'
                    : 'hover:bg-muted/50',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <span
                    className="truncate font-medium"
                    title={order.customer_name ?? undefined}
                >
                    {/* Matches the pane's fallback rather than collapsing to
                        an empty row — an unnamed order still has to be
                        callable from this list. */}
                    {order.customer_name ?? t('Unnamed customer')}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                    {formatRelativeTime(order.ordered_at ?? order.created_at)}
                </span>
            </div>
            <div className="mt-0.5 flex items-center gap-1 text-sm text-muted-foreground">
                <span className="truncate">
                    {order.customer_phone ?? t('No phone on file')}
                </span>
                {/* Names the flag that actually fired — "Duplicate or
                    blacklist flagged" made a screen-reader user open the
                    order to find out which, and the two mean different
                    things on the call. */}
                {(order.is_duplicate_flagged || order.is_blacklist_flagged) && (
                    <AlertTriangle
                        className="size-3.5 shrink-0 text-amber-500"
                        aria-label={
                            order.is_duplicate_flagged &&
                            order.is_blacklist_flagged
                                ? t('Flagged: duplicate order, blacklisted customer')
                                : order.is_duplicate_flagged
                                  ? t('Flagged: duplicate order')
                                  : t('Flagged: blacklisted customer')
                        }
                    />
                )}
            </div>
            <div className="mt-2 flex items-center justify-between gap-2">
                <Badge variant="outline" className={cn('shrink-0', statusColor)}>
                    {status}
                </Badge>
                <span className="truncate font-mono text-xs text-muted-foreground">
                    {order.reference ?? `#${order.id}`}
                </span>
            </div>
        </Card>
    );
}
