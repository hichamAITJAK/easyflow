import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
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
    const [processing, setProcessing] = useState(false);

    const handleDelete = () => {
        setProcessing(true);
        router.delete(bulkDestroy.url(), {
            data: { ids: orderIds },
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                onDeleted();
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    Delete {orderIds.length}{' '}
                    {orderIds.length === 1 ? 'order' : 'orders'}?
                </DialogTitle>
                <DialogDescription>
                    This will remove the selected orders from your active
                    list. This action cannot be undone.
                </DialogDescription>

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={handleDelete}
                    >
                        Delete {orderIds.length === 1 ? 'order' : 'orders'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
