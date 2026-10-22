import { Form } from '@inertiajs/react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { Order } from '@/types';

export function OrderDeleteDialog({
    open,
    onOpenChange,
    order,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
}) {
    const { t } = useTranslation();
    const [deleteCommissions, setDeleteCommissions] = useState(false);

    const close = () => {
        setDeleteCommissions(false);
        onOpenChange(false);
    };

    if (!order) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent>
                <DialogTitle>{t('Delete this order?')}</DialogTitle>
                <DialogDescription>
                    {t(
                        'This will remove the order from your active list. This action cannot be undone.',
                    )}
                </DialogDescription>

                <Form
                    {...OrderController.destroy.form(order.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={close}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="delete_commissions"
                                value={deleteCommissions ? '1' : '0'}
                            />
                            <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3">
                                <Checkbox
                                    id="delete-order-commissions"
                                    checked={deleteCommissions}
                                    onCheckedChange={(checked) =>
                                        setDeleteCommissions(checked === true)
                                    }
                                    className="mt-0.5"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="delete-order-commissions">
                                        {t(
                                            'Also delete the commissions earned on this order',
                                        )}
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'Confirmation and delivery commissions not yet on an invoice are removed. Invoiced commissions are kept.',
                                        )}
                                    </p>
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={close}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {t('Delete order')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
