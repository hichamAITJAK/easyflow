import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import {
    IdCard,
    Mail,
    Phone,
    ShieldCheck,
    User as UserIcon,
} from 'lucide-react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { AvatarDropzone } from '@/components/avatar-dropzone';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { PageProps, UserRole, UserStatus } from '@/types';

const roleLabel: Record<UserRole, string> = {
    super_admin: 'Super Admin',
    admin: 'Owner / Manager',
    confirmation_agent: 'Confirmation Agent',
    fulfilment_agent: 'Fulfilment Agent',
};

const statusLabel: Record<UserStatus, string> = {
    active: 'Active',
    invited: 'Invited',
    disabled: 'Disabled',
};

const statusDotClassName: Record<UserStatus, string> = {
    active: 'bg-emerald-500',
    invited: 'bg-amber-500',
    disabled: 'bg-muted-foreground',
};

export default function Profile({
    mustVerifyEmail,
    status,
    avatarOptions = [],
}: {
    mustVerifyEmail: boolean;
    status?: string;
    avatarOptions?: string[];
}) {
    const { t } = useTranslation();

    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    return (
        <>
            <Head title={t('Profile settings')} />

            <h1 className="sr-only">{t('Profile settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Profile')}
                    description={t('Update your photo, name, email, and phone number')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card>
                                <CardContent className="flex flex-col items-center gap-4 text-center">
                                    <AvatarDropzone
                                        initialPreviewUrl={user.avatar ?? null}
                                        presets={avatarOptions}
                                    />
                                    <FieldError
                                        errors={[{ message: errors.avatar }]}
                                    />

                                    <div className="flex flex-wrap items-center justify-center gap-2 border-t pt-4">
                                        <Badge
                                            variant="outline"
                                            className="gap-1.5"
                                        >
                                            <ShieldCheck className="size-3 text-muted-foreground" />
                                            {t(roleLabel[user.role])}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className="gap-1.5"
                                        >
                                            <span
                                                className={cn(
                                                    'inline-block size-2 rounded-full',
                                                    statusDotClassName[
                                                        user.status
                                                    ],
                                                )}
                                            />
                                            {statusLabel[user.status]}
                                        </Badge>
                                    </div>
                                    <FieldDescription className="-mt-2 max-w-sm">
                                        {t('Role and account status are managed by your Owner or Manager from the team page.')}
                                    </FieldDescription>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <IdCard className="size-5 text-muted-foreground" />
                                        {t('Identity')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('Your name and contact details.')}
                                    </CardDescription>
                                </CardHeader>

                                <CardContent className="space-y-6">
                                    <Field>
                                        <FieldLabel htmlFor="name">
                                            {t('Name')}{' '}
                                            <span className="text-destructive">
                                                *
                                            </span>
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="name"
                                                defaultValue={user.name}
                                                name="name"
                                                required
                                                autoComplete="name"
                                                placeholder={t('Full name')}
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                { message: errors.name },
                                            ]}
                                        />
                                    </Field>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel htmlFor="email">
                                                {t('Email address')}{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </FieldLabel>
                                            <InputGroup>
                                                <InputGroupAddon aria-hidden="true">
                                                    <Mail />
                                                </InputGroupAddon>
                                                <InputGroupInput
                                                    id="email"
                                                    type="email"
                                                    defaultValue={user.email}
                                                    name="email"
                                                    required
                                                    autoComplete="username"
                                                    placeholder={t('Email address')}
                                                />
                                            </InputGroup>
                                            <FieldError
                                                errors={[
                                                    { message: errors.email },
                                                ]}
                                            />
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="phone">
                                                {t('Phone number')}
                                            </FieldLabel>
                                            <InputGroup>
                                                <InputGroupAddon aria-hidden="true">
                                                    <Phone />
                                                </InputGroupAddon>
                                                <InputGroupInput
                                                    id="phone"
                                                    defaultValue={
                                                        user.phone ?? ''
                                                    }
                                                    name="phone"
                                                    autoComplete="tel"
                                                    placeholder="+212 6 00 00 00 00"
                                                />
                                            </InputGroup>
                                            <FieldError
                                                errors={[
                                                    { message: errors.phone },
                                                ]}
                                            />
                                        </Field>
                                    </div>

                                    {mustVerifyEmail &&
                                        user.email_verified_at === null && (
                                            <Alert variant="warning">
                                                <UserIcon />
                                                <AlertTitle>
                                                    {t('Your email address is unverified')}
                                                </AlertTitle>
                                                <AlertDescription>
                                                    <p>
                                                        <Link
                                                            href={send()}
                                                            as="button"
                                                            className="font-medium underline decoration-current/40 underline-offset-4 transition-colors hover:decoration-current"
                                                        >
                                                            Click here to
                                                            re-send the
                                                            verification email.
                                                        </Link>
                                                    </p>
                                                    {status ===
                                                        'verification-link-sent' && (
                                                        <p className="font-medium text-emerald-600 dark:text-emerald-400">
                                                            {t('A new verification link has been sent to your email address.')}
                                                        </p>
                                                    )}
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                </CardContent>
                            </Card>

                            <div className="flex items-center justify-end gap-3 border-t pt-6">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: t('Profile settings'),
            href: edit(),
        },
    ],
};
