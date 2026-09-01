import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { User } from '@/types';
import { FulfilmentAgentForm } from './fulfilment-agent-form';

export function FulfilmentAgentFormDialog({
    open,
    onOpenChange,
    user,
    avatarOptions = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user?: User | null;
    avatarOptions?: string[];
}) {
    const isEditing = Boolean(user);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                key={user?.id ?? 'create'}
                className="flex max-h-[92vh] flex-col gap-0 p-0 sm:max-w-3xl"
            >
                <DialogHeader className="border-b px-6 py-5">
                    <div className="flex items-center justify-between gap-4">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <DialogTitle className="text-xl">
                                    {isEditing
                                        ? 'Edit Fulfilment Agent'
                                        : 'Add Fulfilment Agent'}
                                </DialogTitle>
                                <Badge variant="outline">Fulfilment Agent</Badge>
                            </div>
                            <DialogDescription>
                                {isEditing
                                    ? "Update agent account details and warehouse scanning compensation."
                                    : 'Set up credentials and parcel preparation pay for warehouse scanning.'}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <FulfilmentAgentForm
                    user={user}
                    avatarOptions={avatarOptions}
                    onSuccess={() => onOpenChange(false)}
                    onCancel={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
