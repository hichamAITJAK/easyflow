import type { Errors } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { Building2, Mail, MapPin, Phone } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { LogoDropzone } from '@/components/logo-dropzone';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editBusiness } from '@/routes/business';
import { update as updateBusinessProfile } from '@/routes/business/profile';

type BusinessProfile = {
    name: string;
    legal_name: string | null;
    logo: string | null;
    ice: string | null;
    rc: string | null;
    if_number: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    city: string | null;
};

export default function BusinessSettings({
    business,
}: {
    business: BusinessProfile;
}) {
    const { t } = useTranslation();

    const [logo, setLogo] = useState<File | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    /**
     * Posts multipart so the logo file rides along. Mirrors the courier and
     * platform logo forms, which use the same dropzone — it reports a File
     * to its parent rather than exposing a named input, so native form
     * submission wouldn't carry it.
     */
    const handleProfileSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        const form = new FormData(event.currentTarget);

        if (logo) {
            form.set('logo', logo);
        }

        if (removeLogo) {
            form.set('remove_logo', '1');
        }

        router.post(updateBusinessProfile().url, form, {
            forceFormData: true,
            preserveScroll: true,
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <Head title={t('Business settings')} />

            <h1 className="sr-only">{t('Business settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Business settings')}
                    description={t(
                        'Defaults that apply across your whole business',
                    )}
                />

                <form onSubmit={handleProfileSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Building2 className="size-5 text-muted-foreground" />
                                {t('Business details')}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'These appear on the commission invoices you issue to agents.',
                                )}
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="space-y-6">
                            <Field>
                                <FieldLabel>{t('Logo')}</FieldLabel>
                                <LogoDropzone
                                    initialPreviewUrl={business.logo}
                                    onChange={(file) => {
                                        setLogo(file);

                                        if (file) {
                                            setRemoveLogo(false);
                                        }
                                    }}
                                    onRemove={() => setRemoveLogo(true)}
                                />
                                <FieldDescription>
                                    {t(
                                        'Printed at the top of every invoice you issue.',
                                    )}
                                </FieldDescription>
                                <FieldError
                                    errors={[{ message: errors.logo }]}
                                />
                            </Field>

                            <div className="grid gap-6 sm:grid-cols-2">
                                <Field>
                                    <FieldLabel htmlFor="business_name">
                                        {t('Business name')}{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="business_name"
                                            name="name"
                                            required
                                            defaultValue={business.name}
                                        />
                                    </InputGroup>
                                    <FieldDescription>
                                        {t(
                                            'Your trading name, shown across the app.',
                                        )}
                                    </FieldDescription>
                                    <FieldError
                                        errors={[{ message: errors.name }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="legal_name">
                                        {t('Registered name')}
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="legal_name"
                                            name="legal_name"
                                            defaultValue={
                                                business.legal_name ?? ''
                                            }
                                        />
                                    </InputGroup>
                                    <FieldDescription>
                                        {t(
                                            'Used on invoices when it differs from the trading name.',
                                        )}
                                    </FieldDescription>
                                    <FieldError
                                        errors={[
                                            { message: errors.legal_name },
                                        ]}
                                    />
                                </Field>
                            </div>

                            <div className="grid gap-6 border-t pt-6 sm:grid-cols-3">
                                <Field>
                                    <FieldLabel htmlFor="ice">ICE</FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="ice"
                                            name="ice"
                                            inputMode="numeric"
                                            defaultValue={business.ice ?? ''}
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.ice }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="rc">RC</FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="rc"
                                            name="rc"
                                            defaultValue={business.rc ?? ''}
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.rc }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="if_number">
                                        IF
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="if_number"
                                            name="if_number"
                                            defaultValue={
                                                business.if_number ?? ''
                                            }
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.if_number }]}
                                    />
                                </Field>
                            </div>
                            <FieldDescription className="-mt-2">
                                {t(
                                    "Moroccan company identifiers. Leave blank if they don't apply — they are only printed when set.",
                                )}
                            </FieldDescription>

                            <div className="grid gap-6 border-t pt-6 sm:grid-cols-2">
                                <Field>
                                    <FieldLabel htmlFor="business_phone">
                                        {t('Phone')}
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Phone className="size-4 text-muted-foreground" />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="business_phone"
                                            name="phone"
                                            type="tel"
                                            inputMode="tel"
                                            placeholder="0612345678"
                                            defaultValue={business.phone ?? ''}
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.phone }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="business_email">
                                        {t('Email')}
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Mail className="size-4 text-muted-foreground" />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="business_email"
                                            name="email"
                                            type="email"
                                            defaultValue={business.email ?? ''}
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.email }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="address">
                                        {t('Address')}
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <MapPin className="size-4 text-muted-foreground" />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="address"
                                            name="address"
                                            defaultValue={
                                                business.address ?? ''
                                            }
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.address }]}
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="city">
                                        {t('City')}
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="city"
                                            name="city"
                                            defaultValue={business.city ?? ''}
                                        />
                                    </InputGroup>
                                    <FieldError
                                        errors={[{ message: errors.city }]}
                                    />
                                </Field>
                            </div>
                        </CardContent>

                        <CardFooter className="justify-end border-t">
                            <Button type="submit" disabled={processing}>
                                {processing ? t('Saving…') : t('Save details')}
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </>
    );
}

BusinessSettings.layout = {
    breadcrumbs: [
        {
            title: 'Business settings',
            href: editBusiness(),
        },
    ],
};
