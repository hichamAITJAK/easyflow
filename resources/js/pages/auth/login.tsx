import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

// Taller, rounder fields than the app default: this is the one form a
// merchant types into on a phone at the start of every day.
const FIELD_CLASS =
    'h-12 rounded-xl border-[1.5px] px-4 text-[0.95rem] focus-visible:ring-4 focus-visible:ring-primary/10 focus-visible:border-primary';

export default function Login({ status, canResetPassword }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Log in')} />

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
                    <FieldGroup className="gap-5">
                        <Field>
                            <FieldLabel
                                htmlFor="email"
                                className="text-[0.84rem] font-semibold"
                            >
                                {t('Email')}
                            </FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="you@yourstore.com"
                                className={FIELD_CLASS}
                            />
                            <InputError message={errors.email} />
                        </Field>

                        <Field>
                            <FieldLabel
                                htmlFor="password"
                                className="text-[0.84rem] font-semibold"
                            >
                                {t('Password')}
                            </FieldLabel>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder={t('Enter your password')}
                                className={FIELD_CLASS}
                            />
                            <InputError message={errors.password} />
                        </Field>

                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <Field orientation="horizontal" className="w-auto">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                    className="size-5 rounded-md"
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-[0.88rem] font-normal"
                                >
                                    {t('Keep me logged in')}
                                </Label>
                            </Field>

                            {canResetPassword && (
                                <TextLink
                                    href={request()}
                                    className="text-[0.88rem] font-semibold text-primary no-underline hover:underline"
                                    tabIndex={5}
                                >
                                    {t('Forgot password?')}
                                </TextLink>
                            )}
                        </div>

                        <Field>
                            <Button
                                type="submit"
                                size="lg"
                                className="h-13 w-full rounded-[14px] text-base font-semibold"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('Log in')}
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Welcome back.',
    description: 'Log in to run your cash-on-delivery operation.',
};
