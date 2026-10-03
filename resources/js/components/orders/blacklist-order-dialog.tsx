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
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import type { Order } from '@/types';

/**
 * Blacklists the client behind an order (UC-10) — records a
 * CustomerBlacklistEntry for their phone and flags every one of their
 * orders in this business, not just the one this was opened from. Never
 * offered for test orders (see orderRowActions).
 */
export function BlacklistOrderDialog({
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
                    <DialogTitle>{t('Blacklist customer')}</DialogTitle>
                    <DialogDescription>
                        {order.customer_name ?? t('This client')} (
                        {order.customer_phone}) will be blocked from placing
                        new orders. Every existing order from this phone
                        number will be flagged too.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...OrderController.blacklist.form(order.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="blacklist_reason">
                                    {t('Reason')}
                                </Label>
                                <Input
                                    id="blacklist_reason"
                                    name="reason"
                                    placeholder={t('e.g. Repeated refusals, chargeback history')}
                                />
                                <InputError message={errors.reason} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="blacklist_notes">
                                    {t('Notes (optional)')}
                                </Label>
                                <Textarea
                                    id="blacklist_notes"
                                    name="notes"
                                    placeholder={t('Any additional context')}
                                />
                                <InputError message={errors.notes} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Back
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Blacklist customer
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
