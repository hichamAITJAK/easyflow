import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Product, Store, User } from '@/types';
import { ConfirmationAgentForm } from './confirmation-agent-form';

export function ConfirmationAgentFormDialog({
    open,
    onOpenChange,
    user,
    stores,
    products,
    avatarOptions = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user?: User | null;
    stores: Store[];
    products: Product[];
    avatarOptions?: string[];
}) {
    const isEditing = Boolean(user);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                key={user?.id ?? 'create'}
                className="flex max-h-[92vh] flex-col gap-0 p-0 sm:max-w-4xl"
            >
                <DialogHeader className="border-b px-6 py-5">
                    <div className="flex items-center justify-between gap-4">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <DialogTitle className="text-xl">
                                    {isEditing
                                        ? 'Edit Confirmation Agent'
                                        : 'Add Confirmation Agent'}
                                </DialogTitle>
                                <Badge variant="outline">Confirmation Agent</Badge>
                            </div>
                            <DialogDescription>
                                {isEditing
                                    ? "Update agent details, compensation, store/product scope, and performance targets."
                                    : 'Set up credentials, pay structure, assigned stores/products, and daily targets.'}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <ConfirmationAgentForm
                    user={user}
                    stores={stores}
                    products={products}
                    avatarOptions={avatarOptions}
                    onSuccess={() => onOpenChange(false)}
                    onCancel={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
