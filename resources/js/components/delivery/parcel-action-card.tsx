import {
    AlertTriangle,
    Box,
    CalendarClock,
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { deliveryStatusLabels } from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import type {
    DeliveryAction,
    DeliveryParcel,
    DeliveryReason,
} from '@/types/delivery-guy';

export type ApplyPayload = {
    action: DeliveryAction;
    reason?: DeliveryReason;
    postponed_until?: string;
    note?: string;
};

/** Reasons offered when a delivery did not go through (kept on the history). */
const FAIL_REASONS: { value: DeliveryReason; label: string }[] = [
    { value: 'no_answer', label: 'No answer' },
    { value: 'client_absent', label: 'Client absent' },
    { value: 'wrong_address', label: 'Wrong address' },
    { value: 'other', label: 'Other' },
];

const RETURN_REASONS: { value: DeliveryReason; label: string }[] = [
    { value: 'client_refused', label: 'Client refused' },
    { value: 'payment_issue', label: 'Could not pay' },
    { value: 'wrong_address', label: 'Wrong address' },
    { value: 'no_answer', label: 'Never reached' },
    { value: 'other', label: 'Other' },
];

type Pending = 'failed' | 'postponed' | 'returned' | null;

/**
 * The scanned parcel and what the delivery guy can do with it. One big
 * button for the usual outcome of the current step (take it, deliver
 * it), the exceptions as smaller buttons that ask why or when first.
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
    const [pending, setPending] = useState<Pending>(null);
    const [reason, setReason] = useState<DeliveryReason | ''>('');
    const [date, setDate] = useState('');
    const [note, setNote] = useState('');

    const can = (action: DeliveryAction) => actions.includes(action);
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

    const openDialog = (kind: Exclude<Pending, null>) => {
        setReason('');
        setDate('');
        setNote('');
        setPending(kind);
    };

    const submitDialog = () => {
        if (pending === 'failed' && reason) {
            onApply({
                action: 'delivery_attempt_failed',
                reason,
                note: note || undefined,
            });
        } else if (pending === 'postponed' && date) {
            onApply({
                action: 'postponed',
                postponed_until: date,
                note: note || undefined,
            });
        } else if (pending === 'returned' && reason) {
            onApply({
                action: 'returned_in_transit',
                reason,
                note: note || undefined,
            });
        }

        setPending(null);
    };

    const dialogReady =
        (pending === 'failed' && reason !== '') ||
        (pending === 'postponed' && date !== '') ||
        (pending === 'returned' && reason !== '');

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
                            {can('out_for_delivery') && (
                                <Button
                                    size="lg"
                                    className="h-16 w-full text-base font-semibold"
                                    disabled={applying}
                                    onClick={() =>
                                        onApply({ action: 'out_for_delivery' })
                                    }
                                >
                                    {applying ? <Spinner /> : null}
                                    {t('Take this parcel')}
                                </Button>
                            )}
                            {can('delivered') && (
                                <Button
                                    size="lg"
                                    className="h-16 w-full bg-[#16A08E] text-base font-semibold text-white hover:bg-[#12907f]"
                                    disabled={applying}
                                    onClick={() =>
                                        onApply({ action: 'delivered' })
                                    }
                                >
                                    {applying ? <Spinner /> : null}
                                    {t('Delivered · :amount MAD collected', {
                                        amount: order.total_amount,
                                    })}
                                </Button>
                            )}
                            {(can('delivery_attempt_failed') ||
                                can('postponed') ||
                                can('refused') ||
                                can('returned_in_transit')) && (
                                <div className="grid grid-cols-2 gap-2">
                                    {can('delivery_attempt_failed') && (
                                        <Button
                                            variant="outline"
                                            disabled={applying}
                                            onClick={() => openDialog('failed')}
                                        >
                                            {t('Not delivered')}
                                        </Button>
                                    )}
                                    {can('postponed') && (
                                        <Button
                                            variant="outline"
                                            disabled={applying}
                                            onClick={() =>
                                                openDialog('postponed')
                                            }
                                        >
                                            <CalendarClock />
                                            {t('Postpone')}
                                        </Button>
                                    )}
                                    {can('refused') && (
                                        <Button
                                            variant="outline"
                                            className="text-[#B3281D]"
                                            disabled={applying}
                                            onClick={() =>
                                                onApply({ action: 'refused' })
                                            }
                                        >
                                            {t('Refused')}
                                        </Button>
                                    )}
                                    {can('returned_in_transit') && (
                                        <Button
                                            variant="outline"
                                            className="text-[#B3281D]"
                                            disabled={applying}
                                            onClick={() =>
                                                openDialog('returned')
                                            }
                                        >
                                            {t('Return to warehouse')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {pending === 'postponed'
                                ? t('Postpone delivery')
                                : pending === 'returned'
                                  ? t('Return to warehouse')
                                  : t('Not delivered')}
                        </DialogTitle>
                        <DialogDescription>
                            {pending === 'postponed'
                                ? t('When should it be delivered instead?')
                                : t('Why? This is kept on the parcel history.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4">
                        {pending === 'postponed' ? (
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
                        ) : (
                            <div className="grid gap-1.5">
                                <Label htmlFor="reason">{t('Reason')}</Label>
                                <Select
                                    value={reason}
                                    onValueChange={(value) =>
                                        setReason(value as DeliveryReason)
                                    }
                                >
                                    <SelectTrigger
                                        id="reason"
                                        className="w-full"
                                    >
                                        <SelectValue
                                            placeholder={t('Choose a reason')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(pending === 'returned'
                                            ? RETURN_REASONS
                                            : FAIL_REASONS
                                        ).map((item) => (
                                            <SelectItem
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {t(item.label)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

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
                            onClick={() => setPending(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={!dialogReady}
                            onClick={submitDialog}
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
