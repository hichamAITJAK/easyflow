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

import { useTranslation } from '@/hooks/use-translation';
export function ConnectTutorialDialog({
    open,
    onOpenChange,
    name,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    name: string;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{t('Connect :name', { name })}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Step-by-step guide to connect your :name account.',
                            { name },
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Empty className="min-h-[24rem]">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <BookOpen />
                        </EmptyMedia>
                        <EmptyTitle>{t('Tutorial coming soon')}</EmptyTitle>
                        <EmptyDescription>
                            {t('Steps to connect :name will show up here.', {
                                name,
                            })}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            </DialogContent>
        </Dialog>
    );
}
