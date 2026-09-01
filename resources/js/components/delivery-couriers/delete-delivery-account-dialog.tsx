import { Form } from '@inertiajs/react';
import DeliveryCourrierConnectionController from '@/actions/App/Http/Controllers/DeliveryCouriers/DeliveryCourrierConnectionController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DeliveryAccount } from '@/types';

export function DeleteDeliveryAccountDialog({
    open,
    onOpenChange,
    account,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    account: DeliveryAccount | null;
}) {
    if (!account) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    Disconnect {account.courier?.name ?? 'this courier'}?
                </DialogTitle>
                <DialogDescription>
                    This will remove the stored credentials for this courier.
                    This action cannot be undone.
                </DialogDescription>

                <Form
                    {...DeliveryCourrierConnectionController.destroy.form(
                        account.id,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Disconnect
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
