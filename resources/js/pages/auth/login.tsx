import { Form, Head } from '@inertiajs/react';
import { GoogleLoginButton } from '@/components/google-login-button';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister?: boolean;
};

export default function Login({ status, canResetPassword, canRegister }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <div
                    role="status"
                    className="mb-6 rounded-lg border border-emerald-500/25 bg-emerald-500/10 px-3 py-2 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400"
                >
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field>
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </Field>

                        <Field>
                            <div className="flex items-center justify-between">
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-sm"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Your password"
                            />
                            <InputError message={errors.password} />
                        </Field>

                        <Field orientation="horizontal">
                            <Checkbox id="remember" name="remember" tabIndex={3} />
                            <Label
                                htmlFor="remember"
                                className="font-normal text-muted-foreground"
                            >
                                Remember me
                            </Label>
                        </Field>

                        <Field>
                            <Button
                                type="submit"
                                className="w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </Field>

                        <div className="relative text-center text-sm after:absolute after:inset-x-0 after:top-1/2 after:border-t after:border-border">
                            <span className="relative z-10 bg-background px-3 text-muted-foreground">
                                or
                            </span>
                        </div>

                        <Field>
                            <GoogleLoginButton label="Continue with Google" />
                        </Field>

                        <Field>
                            <PasskeyVerify />
                        </Field>

                        {canRegister && (
                            <p className="text-center text-sm text-muted-foreground">
                                New here?{' '}
                                <TextLink href={register()} tabIndex={6}>
                                    Start your free trial
                                </TextLink>
                            </p>
                        )}
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Log in to pick up where your orders left off.',
};
