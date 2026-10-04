import { Form } from '@inertiajs/react';
import BusinessController from '@/actions/App/Http/Controllers/SuperAdmin/BusinessController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { Business } from '@/types';

export type BusinessStatusIntent = 'suspend' | 'cancel';

/**
 * Copy per intent. Both transitions lock every user of the tenant out at
 * their next login, so each one says exactly that rather than a generic
 * "are you sure" — the consequence is the decision.
 */
const INTENTS: Record<
    BusinessStatusIntent,
    {
        status: string;
        title: string;
        description: string;
        confirm: string;
    }
> = {
    suspend: {
        status: 'suspended',
        title: 'Suspend :name?',
        description:
            'Everyone at this business is signed out of the web and mobile apps until you reactivate them. Their data, stores, and orders are kept.',
        confirm: 'Suspend business',
    },
    cancel: {
        status: 'cancelled',
        title: 'Cancel :name?',
        description:
            'Everyone at this business loses access immediately, and a cancelled business cannot be reactivated from this panel. Their data is kept.',
        confirm: 'Cancel business',
    },
};

export function BusinessStatusDialog({
    open,
    onOpenChange,
    business,
    intent,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    business: Business | null;
    intent: BusinessStatusIntent;
}) {
    const { t } = useTranslation();

    if (!business) {
        return null;
    }

    const copy = INTENTS[intent];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {t(copy.title, { name: business.name })}
                </DialogTitle>
                <DialogDescription>{t(copy.description)}</DialogDescription>

                <Form
                    {...BusinessController.updateStatus.form(business.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="status"
                                value={copy.status}
                            />
                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('Keep as is')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {t(copy.confirm)}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
