import {
    AlertTriangle,
    Box,
    MapPin,
    PackageOpen,
    Phone,
    Repeat,
    Store,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { WhatsAppIcon } from '@/components/icons/whatsapp-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { deliveryStatusLabels } from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { DeliveryAction, DeliveryParcel } from '@/types/delivery-guy';

export type ApplyPayload = {
    action: string;
    postponed_until?: string;
    note?: string;
};

/** How each outcome is coloured: the happy ones stand out, the rest are quiet. */
function toneFor(action: DeliveryAction): string {
    switch (action.status) {
        case 'delivered':
            return 'bg-[#16A08E] text-white hover:bg-[#12907f] border-transparent';
        case 'out_for_delivery':
            return 'bg-primary text-primary-foreground hover:bg-primary/90 border-transparent';
        case 'refused':
        case 'returned_in_transit':
            return 'text-[#B3281D]';
        default:
            return '';
    }
}

/**
 * The scanned parcel and what the delivery guy can mark on it, with the
 * couriers' own labels (Livré, Pas de réponse, Reporté…). The server
 * decides which show from the parcel's status. The big ones (Ramassé,
 * Livré) are the usual path; the rest are the exceptions.
 */
export function ParcelActionCard({
    order,
    actions,
    onApply,
    applying,
}: {
    order: DeliveryParcel;
    actions: DeliveryAction[];
    onApply: (payload: ApplyPayload) => void;
    applying: boolean;
}) {
    const { t } = useTranslation();
    const [dated, setDated] = useState<DeliveryAction | null>(null);
    const [date, setDate] = useState('');
    const [note, setNote] = useState('');

    const digits = order.customer_phone?.replace(/[^\d+]/g, '');
    const whatsapp = order.customer_phone
        ? formatMoroccoPhoneForWhatsApp(order.customer_phone)
        : null;

    const flags = [
        order.parcel_fragile && { label: t('Fragile'), icon: AlertTriangle },
        order.parcel_open && {
            label: t('Open on delivery'),
            icon: PackageOpen,
        },
        order.parcel_replace && { label: t('Replacement'), icon: Repeat },
    ].filter(Boolean) as { label: string; icon: typeof AlertTriangle }[];

    const primary = actions.filter(
        (action) =>
            action.status === 'delivered' ||
            action.status === 'out_for_delivery',
    );
    const others = actions.filter((action) => !primary.includes(action));

    const choose = (action: DeliveryAction) => {
        if (action.needs_date) {
            setDate('');
            setNote('');
            setDated(action);

            return;
        }

        onApply({ action: action.key });
    };

    const submitDated = () => {
        if (dated && date) {
            onApply({
                action: dated.key,
                postponed_until: date,
                note: note || undefined,
            });
        }

        setDated(null);
    };

    return (
        <>
            <Card className="overflow-hidden">
                <CardContent className="space-y-4 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="font-mono text-lg leading-tight font-semibold break-all">
                                {order.courier_tracking_number ?? '—'}
                            </p>
                            {order.reference && (
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t('Order :reference', {
                                        reference: order.reference,
                                    })}
                                </p>
                            )}
                        </div>
                        {order.delivery_status && (
                            <Badge variant="secondary" className="shrink-0">
                                {t(deliveryStatusLabels[order.delivery_status])}
                            </Badge>
                        )}
                    </div>

                    {flags.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {flags.map(({ label, icon: Icon }) => (
                                <Badge
                                    key={label}
                                    variant="destructive"
                                    className="gap-1"
                                >
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
                        <Row icon={Store} value={order.store} />
                    </dl>

                    {digits && (
                        <div className="grid grid-cols-2 gap-2">
                            <Button asChild variant="outline">
                                <a href={`tel:${digits}`}>
                                    <Phone />
                                    {order.customer_phone}
                                </a>
                            </Button>
                            {whatsapp && (
                                <Button
                                    asChild
                                    className="bg-[#25D366] text-white hover:bg-[#1fb356]"
                                >
                                    <a
                                        href={`https://wa.me/${whatsapp}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <WhatsAppIcon />
                                        WhatsApp
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}

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
                                                {item.name ?? t('Unnamed item')}
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
                        <p className="rounded-md bg-muted p-3 text-sm">
                            {order.parcel_note}
                        </p>
                    )}

                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                        <span className="text-sm text-muted-foreground">
                            {t('Cash to collect')}
                        </span>
                        <span className="font-mono text-lg font-semibold tabular-nums">
                            {order.total_amount} MAD
                        </span>
                    </div>

                    {actions.length === 0 ? (
                        <p className="rounded-md border border-dashed p-3 text-center text-sm text-muted-foreground">
                            {order.delivery_status === 'awaiting_pickup'
                                ? t('Not scanned out by the warehouse yet.')
                                : t('Nothing to do for this parcel right now.')}
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {primary.map((action) => (
                                <Button
                                    key={action.key}
                                    size="lg"
                                    className={cn(
                                        'h-14 w-full text-base font-semibold',
                                        toneFor(action),
                                    )}
                                    disabled={applying}
                                    onClick={() => choose(action)}
                                >
                                    {applying ? <Spinner /> : null}
                                    {action.label}
                                    {action.status === 'delivered' &&
                                        ` · ${order.total_amount} MAD`}
                                </Button>
                            ))}

                            {others.length > 0 && (
                                <div className="grid grid-cols-2 gap-2">
                                    {others.map((action) => (
                                        <Button
                                            key={action.key}
                                            variant="outline"
                                            className={cn(
                                                'h-11 whitespace-normal',
                                                toneFor(action),
                                            )}
                                            disabled={applying}
                                            onClick={() => choose(action)}
                                        >
                                            {action.label}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={dated !== null}
                onOpenChange={(open) => !open && setDated(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{dated?.label}</DialogTitle>
                        <DialogDescription>
                            {t('When should it be delivered instead?')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="postponed_until">
                                {t('New date')}
                            </Label>
                            <Input
                                id="postponed_until"
                                type="date"
                                value={date}
                                min={new Date().toISOString().slice(0, 10)}
                                onChange={(event) =>
                                    setDate(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="delivery_note">
                                {t('Note (optional)')}
                            </Label>
                            <Input
                                id="delivery_note"
                                value={note}
                                maxLength={255}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDated(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={!date}
                            onClick={submitDated}
                        >
                            {t('Confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
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
