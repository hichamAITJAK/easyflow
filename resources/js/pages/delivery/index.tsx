import { Head } from '@inertiajs/react';
import { MapPin, PackageCheck, Phone, Truck } from 'lucide-react';
import { WhatsAppIcon } from '@/components/icons/whatsapp-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useTranslation } from '@/hooks/use-translation';
import {
    deliveryStatusColors,
    deliveryStatusLabels,
    statusChipClass,
} from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { Order } from '@/types';

type Parcel = Pick<
    Order,
    | 'id'
    | 'reference'
    | 'courier_tracking_number'
    | 'customer_name'
    | 'customer_phone'
    | 'customer_address'
    | 'customer_city'
    | 'total_amount'
    | 'delivery_status'
    | 'parcel_note'
    | 'shipped_at'
>;

/**
 * The delivery guy's parcels for his city. Read-only for now: the
 * delivered / failed actions come with the delivery space proper.
 */
export default function DeliveryIndex({
    city,
    parcels,
    deliveredToday,
}: {
    city: string | null;
    parcels: Parcel[];
    deliveredToday: number;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('My parcels')} />

            <div className="mx-auto max-w-3xl space-y-4 p-4">
                <div className="flex items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('My parcels')}
                        </h1>
                        <p className="mt-0.5 flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin className="size-3.5" />
                            {city ?? t('No city set')}
                        </p>
                    </div>
                    <div className="text-right">
                        <div className="font-mono text-2xl leading-none font-semibold tabular-nums">
                            {deliveredToday}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {t('delivered today')}
                        </div>
                    </div>
                </div>

                {parcels.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>{t('Nothing to deliver')}</EmptyTitle>
                            <EmptyDescription>
                                {t(
                                    'Parcels handed to you by the confirmation team will show up here.',
                                )}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="grid gap-3">
                        {parcels.map((parcel) => {
                            const digits = parcel.customer_phone?.replace(
                                /[^\d+]/g,
                                '',
                            );
                            const whatsapp = parcel.customer_phone
                                ? formatMoroccoPhoneForWhatsApp(
                                      parcel.customer_phone,
                                  )
                                : null;

                            return (
                                <Card key={parcel.id} size="sm">
                                    <CardContent className="space-y-3 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">
                                                    {parcel.customer_name}
                                                </div>
                                                <div className="truncate text-sm text-muted-foreground">
                                                    {parcel.customer_address}
                                                </div>
                                                <div className="mt-1 font-mono text-xs text-muted-foreground">
                                                    {parcel.courier_tracking_number ??
                                                        parcel.reference}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <div className="font-mono font-semibold tabular-nums">
                                                    {parcel.total_amount} MAD
                                                </div>
                                                {parcel.delivery_status && (
                                                    <Badge
                                                        variant="outline"
                                                        className={cn(
                                                            statusChipClass,
                                                            'mt-1',
                                                            deliveryStatusColors[
                                                                parcel
                                                                    .delivery_status
                                                            ],
                                                        )}
                                                    >
                                                        {t(
                                                            deliveryStatusLabels[
                                                                parcel
                                                                    .delivery_status
                                                            ],
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>

                                        {parcel.parcel_note && (
                                            <p className="text-sm text-muted-foreground">
                                                {parcel.parcel_note}
                                            </p>
                                        )}

                                        {digits && (
                                            <div className="grid grid-cols-2 gap-2">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <a href={`tel:${digits}`}>
                                                        <Phone />
                                                        {t('Call')}
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
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <PackageCheck className="size-3.5" />
                    {t('Marking parcels delivered from here is coming next.')}
                </p>
            </div>
        </>
    );
}
