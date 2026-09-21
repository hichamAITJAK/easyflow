import { AlertTriangle, Box, MapPin, PackageOpen, Repeat, Store, Truck, User } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { deliveryStatusLabels } from '@/lib/order-status';
import type { FulfillmentAction, FulfillmentOrder } from '@/types/fulfillment';

/** The wording on the commit button, per action the server allows. */
const ACTION_LABELS: Record<NonNullable<FulfillmentAction>, string> = {
    ready_for_pickup: 'Mark ready for pickup',
    return_received: 'Confirm return received',
};

/**
 * The scanned parcel, and the single thing that may be done with it.
 *
 * The agent never picks the action — `action` is re-derived server-side
 * from the parcel's current status (see the FulfillmentController
 * docblock). When it is null the card renders without a button and says
 * why, rather than showing a disabled control the agent will keep tapping.
 */
export function ParcelCard({
    order,
    action,
    onConfirm,
    confirming,
}: {
    order: FulfillmentOrder;
    action: FulfillmentAction;
    onConfirm: () => void;
    confirming: boolean;
}) {
    // The courier-facing handling flags. Surfaced as loud chips because
    // they change how the parcel is physically handled, and this card is
    // the only place the agent sees them.
    const flags = [
        order.parcel_fragile && { label: 'Fragile', icon: AlertTriangle },
        order.parcel_open && { label: 'Open on delivery', icon: PackageOpen },
        order.parcel_replace && { label: 'Replacement', icon: Repeat },
    ].filter(Boolean) as { label: string; icon: typeof AlertTriangle }[];

    return (
        <Card className="overflow-hidden">
            <CardContent className="space-y-4 p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="font-mono text-lg leading-tight font-semibold break-all">
                            {order.courier_tracking_number ?? '—'}
                        </p>
                        {order.reference && (
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Order {order.reference}
                            </p>
                        )}
                    </div>
                    {order.delivery_status && (
                        <Badge variant="secondary" className="shrink-0">
                            {deliveryStatusLabels[order.delivery_status]}
                        </Badge>
                    )}
                </div>

                {flags.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {flags.map(({ label, icon: Icon }) => (
                            <Badge key={label} variant="destructive" className="gap-1">
                                <Icon className="size-3" />
                                {label}
                            </Badge>
                        ))}
                    </div>
                )}

                <Separator />

                <dl className="space-y-2 text-sm">
                    <Row icon={User} value={order.customer_name} />
                    <Row
                        icon={MapPin}
                        value={[order.customer_address, order.customer_city]
                            .filter(Boolean)
                            .join(', ')}
                    />
                    <Row icon={Truck} value={order.courier} />
                    <Row icon={Store} value={order.store} />
                </dl>

                {order.items.length > 0 && (
                    <>
                        <Separator />
                        <ul className="space-y-2">
                            {order.items.map((item, index) => (
                                <li
                                    key={`${item.sku ?? item.name ?? 'item'}-${index}`}
                                    className="flex items-center gap-3"
                                >
                                    {item.thumbnail ? (
                                        <img
                                            src={item.thumbnail}
                                            alt=""
                                            className="size-10 shrink-0 rounded-md border object-cover"
                                        />
                                    ) : (
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted">
                                            <Box className="size-4 text-muted-foreground" />
                                        </div>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {item.name ?? 'Unnamed item'}
                                        </p>
                                        {item.sku && (
                                            <p className="truncate font-mono text-xs text-muted-foreground">
                                                {item.sku}
                                            </p>
                                        )}
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                                        ×{item.quantity}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {order.parcel_note && (
                    <p className="rounded-md bg-muted p-3 text-sm">{order.parcel_note}</p>
                )}

                {action ? (
                    <Button
                        size="lg"
                        // The primary action of the whole screen, tapped once
                        // per parcel at speed — sized to be unmissable.
                        className="h-16 w-full text-base font-semibold"
                        onClick={onConfirm}
                        disabled={confirming}
                    >
                        {confirming ? <Spinner /> : null}
                        {ACTION_LABELS[action]}
                    </Button>
                ) : (
                    <p className="rounded-md border border-dashed p-3 text-center text-sm text-muted-foreground">
                        Nothing to do for this parcel right now.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function Row({
    icon: Icon,
    value,
}: {
    icon: typeof User;
    value: string | null | undefined;
}) {
    if (!value) {
        return null;
    }

    return (
        <div className="flex items-start gap-2">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <dd className="min-w-0">{value}</dd>
        </div>
    );
}
