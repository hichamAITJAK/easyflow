import { Form, Head } from '@inertiajs/react';
import { KeyRound, Lock, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Update password"
                    description="Ensure your account is using a long, random password to stay secure"
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <ShieldCheck className="size-5 text-muted-foreground" />
                                        Password
                                    </CardTitle>
                                    <CardDescription>
                                        Choose a long, random password to keep
                                        your account secure.
                                    </CardDescription>
                                </CardHeader>

                                <CardContent className="space-y-6">
                                    <Field>
                                        <FieldLabel htmlFor="current_password">
                                            <Lock className="size-3.5 text-muted-foreground" />
                                            Current password
                                        </FieldLabel>

                                        <PasswordInput
                                            id="current_password"
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            className="h-10 w-full"
                                            autoComplete="current-password"
                                            placeholder="Current password"
                                        />

                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        errors.current_password,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel htmlFor="password">
                                                <KeyRound className="size-3.5 text-muted-foreground" />
                                                New password
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password"
                                                ref={passwordInput}
                                                name="password"
                                                className="h-10 w-full"
                                                autoComplete="new-password"
                                                placeholder="New password"
                                                passwordrules={
                                                    props.passwordRules
                                                }
                                            />

                                            <FieldError
                                                errors={[
                                                    {
                                                        message:
                                                            errors.password,
                                                    },
                                                ]}
                                            />
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="password_confirmation">
                                                Confirm password
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                className="h-10 w-full"
                                                autoComplete="new-password"
                                                placeholder="Confirm password"
                                                passwordrules={
                                                    props.passwordRules
                                                }
                                            />

                                            <FieldError
                                                errors={[
                                                    {
                                                        message:
                                                            errors.password_confirmation,
                                                    },
                                                ]}
                                            />
                                        </Field>
                                    </div>
                                </CardContent>
                            </Card>

                            <div className="flex items-center justify-end gap-3 border-t pt-6">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
