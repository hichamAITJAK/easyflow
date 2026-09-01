import { BookOpen } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';

export function ConnectTutorialDialog({
    open,
    onOpenChange,
    name,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    name: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Connect {name}</DialogTitle>
                    <DialogDescription>
                        Step-by-step guide to connect your {name} account.
                    </DialogDescription>
                </DialogHeader>

                <Empty className="min-h-[24rem]">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <BookOpen />
                        </EmptyMedia>
                        <EmptyTitle>Tutorial coming soon</EmptyTitle>
                        <EmptyDescription>
                            Steps to connect {name} will show up here.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            </DialogContent>
        </Dialog>
    );
}
