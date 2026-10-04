import { Form } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { User } from '@/types';

export function DeleteUserDialog({
    open,
    onOpenChange,
    user,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User | null;
}) {
    const { t } = useTranslation();

    if (!user) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {t('Remove :name?', { name: user.name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'This will permanently remove this team member and their access. This action cannot be undone.',
                    )}
                </DialogDescription>

                <Form
                    {...UserController.destroy.form(user.id)}
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
                                {t('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {t('Remove member')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
