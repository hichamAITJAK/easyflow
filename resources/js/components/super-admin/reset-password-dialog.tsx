import { Form } from '@inertiajs/react';
import BusinessController from '@/actions/App/Http/Controllers/SuperAdmin/BusinessController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { useTranslation } from '@/hooks/use-translation';
import type { User } from '@/types';

/**
 * Super admin sets a new password for one user of a business — the way
 * out for an admin who locked themselves out, since nobody inside the
 * tenant can reset the admin's password.
 */
export function ResetPasswordDialog({
    open,
    onOpenChange,
    businessId,
    user,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    businessId: number;
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
                    {t('Reset password for :name', { name: user.name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'They will sign in with this new password from now on. Their mobile sessions are signed out.',
                    )}
                </DialogDescription>

                <Form
                    {...BusinessController.resetUserPassword.form({
                        business: businessId,
                        user: user.id,
                    })}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field>
                                    <FieldLabel htmlFor="reset-password">
                                        {t('New Password')}
                                    </FieldLabel>
                                    <PasswordInput
                                        id="reset-password"
                                        name="password"
                                        required
                                        autoFocus
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="reset-password-confirmation">
                                        {t('Confirm Password')}
                                    </FieldLabel>
                                    <PasswordInput
                                        id="reset-password-confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </Field>
                            </FieldGroup>
                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t('Reset password')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
