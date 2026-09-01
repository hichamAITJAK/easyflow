import { router } from '@inertiajs/react';
import { CheckSquare, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    confirmationStatusColors,
    MANUALLY_SELECTABLE_CONFIRMATION_STATUSES,
} from '@/lib/order-status';
import type { OrderConfirmationStatus } from '@/types/order';

/**
 * Built from the shared manually-selectable list rather than the full label
 * map, so a system-owned status can never be reachable in bulk after being
 * hidden from the single-order picker.
 *
 * "Cancelled" is additionally excluded here: UC-9 requires a structured
 * reason code per order, which a one-status-for-many dialog can't collect.
 */
const BULK_STATUS_OPTIONS = MANUALLY_SELECTABLE_CONFIRMATION_STATUSES.filter(
    ([value]) => value !== 'cancelled',
);

export function OrderBulkStatusDialog({
    open,
    onOpenChange,
    orderIds,
    onUpdated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderIds: number[];
    onUpdated: () => void;
}) {
    const [selectedStatus, setSelectedStatus] = useState<string>('');
    const [processing, setProcessing] = useState(false);

    const handleUpdate = () => {
        if (!selectedStatus) {
            return;
        }

        setProcessing(true);

        router.patch(
            '/orders/bulk/status',
            { ids: orderIds, confirmation_status: selectedStatus },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setSelectedStatus('');
                    onUpdated();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle className="flex items-center gap-2">
                    <CheckSquare className="size-5 text-primary" />
                    Update Status for {orderIds.length}{' '}
                    {orderIds.length === 1 ? 'order' : 'orders'}
                </DialogTitle>
                <DialogDescription>
                    Select a new confirmation status to apply across all selected orders simultaneously.
                </DialogDescription>

                <div className="py-4">
                    <Select
                        value={selectedStatus}
                        onValueChange={setSelectedStatus}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select target status..." />
                        </SelectTrigger>
                        <SelectContent>
                            {BULK_STATUS_OPTIONS.map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    <Badge
                                        variant="outline"
                                        className={
                                            confirmationStatusColors[
                                                value as OrderConfirmationStatus
                                            ]
                                        }
                                    >
                                        {label}
                                    </Badge>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

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
                        disabled={!selectedStatus || processing}
                        onClick={handleUpdate}
                    >
                        {processing && <Loader2 className="mr-2 size-4 animate-spin" />}
                        Update Status
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
