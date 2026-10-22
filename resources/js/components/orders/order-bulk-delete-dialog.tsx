import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { bulkDestroy } from '@/routes/orders';

export function OrderBulkDeleteDialog({
    open,
    onOpenChange,
    orderIds,
    onDeleted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderIds: number[];
    onDeleted: () => void;
}) {
    const { t } = useTranslation();

    const [processing, setProcessing] = useState(false);
    const [deleteCommissions, setDeleteCommissions] = useState(false);

    const close = () => {
        setDeleteCommissions(false);
        onOpenChange(false);
    };

    const handleDelete = () => {
        setProcessing(true);
        router.delete(bulkDestroy.url(), {
            data: { ids: orderIds, delete_commissions: deleteCommissions },
            preserveScroll: true,
            onSuccess: () => {
                close();
                onDeleted();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const single = orderIds.length === 1;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent>
                <DialogTitle>
                    {single
                        ? t('Delete :count order?', { count: orderIds.length })
                        : t('Delete :count orders?', {
                              count: orderIds.length,
                          })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'This will remove the selected orders from your active list. This action cannot be undone.',
                    )}
                </DialogDescription>

                <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3">
                    <Checkbox
                        id="bulk-delete-order-commissions"
                        checked={deleteCommissions}
                        onCheckedChange={(checked) =>
                            setDeleteCommissions(checked === true)
                        }
                        className="mt-0.5"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="bulk-delete-order-commissions">
                            {t(
                                'Also delete the commissions earned on these orders',
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
                    <Button type="button" variant="secondary" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={handleDelete}
                    >
                        {single ? t('Delete order') : t('Delete orders')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
