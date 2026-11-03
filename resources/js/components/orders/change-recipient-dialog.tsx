import { Form } from '@inertiajs/react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import type { Order } from '@/types';

/**
 * Statuses where the parcel is still at the courier but can no longer
 * change hands (it is coming back, or already finished).
 */
const CLOSED_STATUSES = [
    'delivered',
    'cancelled_at_courier',
    'refused',
    'returned_in_transit',
    'return_received',
];

/**
 * Only Onessta lets a shipped parcel change recipient; the backend
 * enforces the same rule (see ChangeRecipientRequest::authorize).
 */
export function canChangeRecipient(order: Order): boolean {
    return (
        order.courier_slug === 'Onessta' &&
        order.courier_tracking_number !== null &&
        order.delivery_status !== null &&
        !CLOSED_STATUSES.includes(order.delivery_status)
    );
}

/**
 * Changes who receives a shipped Onessta parcel — name, phone, address
 * and note. The city stays the same; Onessta keeps both recipients in its
 * own history.
 */
export function ChangeRecipientDialog({
    open,
    onOpenChange,
    order,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
}) {
    const { t } = useTranslation();

    if (!order) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('Change recipient')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Parcel :code keeps its city. Onessta is told right away.',
                            { code: order.courier_tracking_number ?? '' },
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...OrderController.changeRecipient.form(order.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="recipient_name">
                                    {t('Name')}
                                </Label>
                                <Input
                                    id="recipient_name"
                                    name="customer_name"
                                    defaultValue={order.customer_name ?? ''}
                                    maxLength={30}
                                    required
                                />
                                <InputError message={errors.customer_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="recipient_phone">
                                    {t('Phone')}
                                </Label>
                                <Input
                                    id="recipient_phone"
                                    name="customer_phone"
                                    type="tel"
                                    inputMode="tel"
                                    defaultValue={order.customer_phone ?? ''}
                                    required
                                />
                                <InputError message={errors.customer_phone} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="recipient_address">
                                    {t('Address')}
                                </Label>
                                <Textarea
                                    id="recipient_address"
                                    name="customer_address"
                                    defaultValue={order.customer_address ?? ''}
                                    maxLength={255}
                                    required
                                />
                                <InputError message={errors.customer_address} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="recipient_note">
                                    {t('Note (optional)')}
                                </Label>
                                <Textarea
                                    id="recipient_note"
                                    name="parcel_note"
                                    defaultValue={order.parcel_note ?? ''}
                                    maxLength={1000}
                                />
                                <InputError message={errors.parcel_note} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('Back')}
                                </Button>
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('Change recipient')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
