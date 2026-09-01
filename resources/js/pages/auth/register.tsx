import { Form, Head } from '@inertiajs/react';
import { GoogleLoginButton } from '@/components/google-login-button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules?: string;
    trialDays: number;
};

export default function Register({ passwordRules, trialDays }: Props) {
    return (
        <>
            <Head title="Create your account" />

            <div className="space-y-6">
                <GoogleLoginButton label="Sign up with Google" />

                <div className="relative text-center text-sm after:absolute after:inset-x-0 after:top-1/2 after:border-t after:border-border">
                    <span className="relative z-10 bg-background px-3 text-muted-foreground">
                        or with email
                    </span>
                </div>

                <Form
                    {...store.form()}
                    resetOnSuccess={['password', 'password_confirmation']}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <Field>
                                <FieldLabel htmlFor="business_name">
                                    Business name
                                </FieldLabel>
                                <Input
                                    id="business_name"
                                    name="business_name"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="organization"
                                    placeholder="e.g. Atlas Store"
                                />
                                <InputError message={errors.business_name} />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="name">
                                    Your name
                                </FieldLabel>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    tabIndex={2}
                                    autoComplete="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="email">Email</FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    placeholder="Choose a password"
                                    passwordrules={passwordRules}
                                />
                                {passwordRules && (
                                    <FieldDescription>
                                        {passwordRules}
                                    </FieldDescription>
                                )}
                                <InputError message={errors.password} />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="password_confirmation">
                                    Confirm password
                                </FieldLabel>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    placeholder="Repeat the password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </Field>

                            <Field>
                                <Button
                                    type="submit"
                                    className="w-full"
                                    tabIndex={6}
                                    disabled={processing}
                                    data-test="register-button"
                                >
                                    {processing && <Spinner />}
                                    Start free trial
                                </Button>
                                <FieldDescription className="text-center">
                                    Your first {trialDays} days are free — no
                                    card, no commitment.
                                </FieldDescription>
                            </Field>

                            <p className="text-center text-sm text-muted-foreground">
                                Already have an account?{' '}
                                <TextLink href={login()} tabIndex={7}>
                                    Log in
                                </TextLink>
                            </p>
                        </FieldGroup>
                    )}
                </Form>
            </div>
        </>
    );
}

Register.layout = {
    title: 'Create your account',
    description: 'Every order, courier, and store in one place.',
};
