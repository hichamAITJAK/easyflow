import { Form } from '@inertiajs/react';
import { useState } from 'react';
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
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import {
    CANCEL_REASON_GROUPS,
    cancellationReasonLabels,
} from '@/lib/order-status';
import type { Order, OrderCancelReason } from '@/types';

/**
 * Collects the structured reason UC-9 requires before an order can be
 * cancelled — `other` additionally requires a free-text note, never as a
 * substitute for a real code. Opened by the confirmation-status column's
 * dropdown when "Cancelled" is selected, instead of persisting the change
 * immediately like every other status does.
 */
export function CancelOrderDialog({
    open,
    onOpenChange,
    order,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
}) {
    const { t } = useTranslation();

    const [reasonCode, setReasonCode] = useState<OrderCancelReason | ''>('');

    if (!order) {
        return null;
    }

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            setReasonCode('');
        }

        onOpenChange(nextOpen);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('Cancel order')}</DialogTitle>
                    {/* Names the subject — the agent was being asked to make
                        an irreversible decision about an anonymous record,
                        with no way to catch a wrong selection from here. */}
                    {/* Says only what's true: the reason is recorded on the
                        order and shows in its history. It is not permanent —
                        an owner or manager can move the order back out of
                        cancelled — so this no longer claims it can't be
                        changed. */}
                    <DialogDescription>
                        {t('Cancelling')}{' '}
                        <span className="font-medium text-foreground">
                            {order.customer_name ?? t('this order')}
                        </span>
                        {order.reference ? ` · ${order.reference}` : ''}.{' '}
                        {t('Choose why — the reason is recorded on the order and shows in its history.')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...OrderController.updateStatus.form(order.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => handleOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="confirmation_status"
                                value="cancelled"
                            />

                            <div className="grid gap-2">
                                <RadioGroup
                                    value={reasonCode}
                                    aria-label={t('Cancellation reason')}
                                    onValueChange={(value) =>
                                        setReasonCode(
                                            value as OrderCancelReason,
                                        )
                                    }
                                    className="gap-4"
                                >
                                    {CANCEL_REASON_GROUPS.map((group) => (
                                        <div
                                            key={group.label}
                                            className="grid gap-2"
                                        >
                                            <span className="text-xs font-medium text-muted-foreground">
                                                {group.label}
                                            </span>
                                            {group.reasons.map((value) => (
                                                <div
                                                    key={value}
                                                    className="flex items-center gap-2"
                                                >
                                                    <RadioGroupItem
                                                        id={`cancel-reason-${value}`}
                                                        value={value}
                                                    />
                                                    <Label
                                                        htmlFor={`cancel-reason-${value}`}
                                                        className="font-normal"
                                                    >
                                                        {
                                                            cancellationReasonLabels[
                                                                value
                                                            ]
                                                        }
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                                </RadioGroup>
                                <input
                                    type="hidden"
                                    name="cancellation_reason_code"
                                    value={reasonCode}
                                />
                                <InputError
                                    message={errors.cancellation_reason_code}
                                />
                            </div>

                            {reasonCode === 'other' && (
                                <div className="grid gap-2">
                                    {/* "Other" is the one code that carries no
                                        meaning on its own, so the label states
                                        the requirement instead of leaving the
                                        agent to discover it at submit. */}
                                    <Label htmlFor="cancellation_note">
                                        What happened? (required)
                                    </Label>
                                    <Textarea
                                        id="cancellation_note"
                                        name="cancellation_note"
                                        placeholder={t('e.g. Client asked to reorder next month')}
                                        required
                                    />
                                    <InputError
                                        message={errors.cancellation_note}
                                    />
                                </div>
                            )}

                            <DialogFooter>
                                {/* "Cancel" as the dismiss label would mean
                                    the opposite of "Cancel order" beside it.
                                    "Keep order" names what dismissing
                                    actually preserves. */}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOpenChange(false)}
                                >
                                    Keep order
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={processing || !reasonCode}
                                >
                                    {processing
                                        ? t('Cancelling…')
                                        : t('Cancel this order')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
