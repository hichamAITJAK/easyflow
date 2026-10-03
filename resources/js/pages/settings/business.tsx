import type { Errors } from '@inertiajs/core';
import { Form, Head, router } from '@inertiajs/react';
import {
    Building2,
    Gauge,
    Mail,
    MapPin,
    Phone,
    Target,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import BusinessController from '@/actions/App/Http/Controllers/Settings/BusinessController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editBusiness } from '@/routes/business';
import { update as updateBusinessProfile } from '@/routes/business/profile';

/**
 * Business-wide performance targets — what an agent is measured against
 * when they have no target of their own. Saving here takes effect
 * immediately for every such agent: the evaluator, the nightly warning
 * command and both dashboards read these rows directly.
 */
type TargetPeriod = 'daily' | 'weekly' | 'monthly';

const PERIOD_OPTIONS: { value: TargetPeriod; label: string }[] = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];

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
    targets,
    minOrdersForEvaluation,
}: {
    business: BusinessProfile;
    targets: {
        confirmation_rate: number;
        delivery_success_rate: number;
        confirmation_rate_period: TargetPeriod;
        delivery_success_rate_period: TargetPeriod;
        confirmation_rate_bonus: number | null;
        delivery_success_rate_bonus: number | null;
    };
    minOrdersForEvaluation: number;
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
                    description={t('Defaults that apply across your whole business')}
                />

                <form onSubmit={handleProfileSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Building2 className="size-5 text-muted-foreground" />
                                {t('Business details')}
                            </CardTitle>
                            <CardDescription>
                                {t('These appear on the commission invoices you issue to agents.')}
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
                                    {t('Printed at the top of every invoice you issue.')}
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
                                        {t('Your trading name, shown across the app.')}
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
                                        {t('Used on invoices when it differs from the trading name.')}
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
                                {t("Moroccan company identifiers. Leave blank if they don't apply — they are only printed when set.")}
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
                                    <FieldLabel htmlFor="city">{t('City')}</FieldLabel>
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

                <Form
                    {...BusinessController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Target className="size-5 text-muted-foreground" />
                                    {t('Performance targets')}
                                </CardTitle>
                                <CardDescription>
                                    {t('Agents are measured against these unless they have their own target set on the team page.')}
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="space-y-6">
                                <Field>
                                    <FieldLabel htmlFor="confirmation_rate">
                                        {t('Confirmation rate target')}{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Gauge className="size-4 text-muted-foreground" />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="confirmation_rate"
                                            name="targets[confirmation_rate]"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={100}
                                            step="0.01"
                                            required
                                            defaultValue={
                                                targets.confirmation_rate
                                            }
                                        />
                                        <InputGroupAddon align="inline-end">
                                            %
                                        </InputGroupAddon>
                                    </InputGroup>
                                    <FieldDescription>
                                        {t('Share of assigned orders an agent is expected to confirm.')}
                                    </FieldDescription>
                                    <FieldError
                                        errors={[
                                            {
                                                message:
                                                    errors[
                                                        'targets.confirmation_rate'
                                                    ],
                                            },
                                        ]}
                                    />
                                </Field>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel htmlFor="confirmation_rate_period">
                                            {t('Measured over')}
                                        </FieldLabel>
                                        <Select
                                            name="targets[confirmation_rate_period]"
                                            defaultValue={
                                                targets.confirmation_rate_period
                                            }
                                        >
                                            <SelectTrigger
                                                id="confirmation_rate_period"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PERIOD_OPTIONS.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {t(option.label)}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <FieldDescription>
                                            {t('Rolling window the rate is summed over.')}
                                        </FieldDescription>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        errors[
                                                            'targets.confirmation_rate_period'
                                                        ],
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="confirmation_rate_bonus">
                                            {t('Bonus when met')}
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="confirmation_rate_bonus"
                                                name="targets[confirmation_rate_bonus]"
                                                type="number"
                                                inputMode="decimal"
                                                min={0}
                                                step="0.01"
                                                placeholder={t('No bonus')}
                                                defaultValue={
                                                    targets.confirmation_rate_bonus ??
                                                    ''
                                                }
                                            />
                                            <InputGroupAddon align="inline-end">
                                                MAD
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldDescription>
                                            {t('Leave blank for no bonus on this target.')}
                                        </FieldDescription>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        errors[
                                                            'targets.confirmation_rate_bonus'
                                                        ],
                                                },
                                            ]}
                                        />
                                    </Field>
                                </div>

                                <Field>
                                    <FieldLabel htmlFor="delivery_success_rate">
                                        {t('Delivery success rate target')}{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Truck className="size-4 text-muted-foreground" />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="delivery_success_rate"
                                            name="targets[delivery_success_rate]"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={100}
                                            step="0.01"
                                            required
                                            defaultValue={
                                                targets.delivery_success_rate
                                            }
                                        />
                                        <InputGroupAddon align="inline-end">
                                            %
                                        </InputGroupAddon>
                                    </InputGroup>
                                    <FieldDescription>
                                        {t('Share of shipped parcels expected to be delivered rather than returned.')}
                                    </FieldDescription>
                                    <FieldError
                                        errors={[
                                            {
                                                message:
                                                    errors[
                                                        'targets.delivery_success_rate'
                                                    ],
                                            },
                                        ]}
                                    />
                                </Field>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel htmlFor="delivery_success_rate_period">
                                            {t('Measured over')}
                                        </FieldLabel>
                                        <Select
                                            name="targets[delivery_success_rate_period]"
                                            defaultValue={
                                                targets.delivery_success_rate_period
                                            }
                                        >
                                            <SelectTrigger
                                                id="delivery_success_rate_period"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PERIOD_OPTIONS.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {t(option.label)}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <FieldDescription>
                                            {t('Rolling window the rate is summed over.')}
                                        </FieldDescription>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        errors[
                                                            'targets.delivery_success_rate_period'
                                                        ],
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="delivery_success_rate_bonus">
                                            {t('Bonus when met')}
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="delivery_success_rate_bonus"
                                                name="targets[delivery_success_rate_bonus]"
                                                type="number"
                                                inputMode="decimal"
                                                min={0}
                                                step="0.01"
                                                placeholder={t('No bonus')}
                                                defaultValue={
                                                    targets.delivery_success_rate_bonus ??
                                                    ''
                                                }
                                            />
                                            <InputGroupAddon align="inline-end">
                                                MAD
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldDescription>
                                            {t('Leave blank for no bonus on this target.')}
                                        </FieldDescription>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        errors[
                                                            'targets.delivery_success_rate_bonus'
                                                        ],
                                                },
                                            ]}
                                        />
                                    </Field>
                                </div>

                                <Field className="border-t pt-6">
                                    <FieldLabel htmlFor="min_orders_for_evaluation">
                                        {t('Minimum orders before judging')}{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupInput
                                            id="min_orders_for_evaluation"
                                            name="min_orders_for_evaluation"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={1000}
                                            required
                                            defaultValue={
                                                minOrdersForEvaluation
                                            }
                                        />
                                        <InputGroupAddon align="inline-end">
                                            orders
                                        </InputGroupAddon>
                                    </InputGroup>
                                    <FieldDescription>
                                        {t("An agent is never warned until they have handled this many orders in the period — a rate from a handful of orders isn't evidence.")}
                                    </FieldDescription>
                                    <FieldError
                                        errors={[
                                            {
                                                message:
                                                    errors.min_orders_for_evaluation,
                                            },
                                        ]}
                                    />
                                </Field>
                            </CardContent>

                            <CardFooter className="justify-end border-t">
                                <Button type="submit" disabled={processing}>
                                    {processing ? t('Saving…') : t('Save changes')}
                                </Button>
                            </CardFooter>
                        </Card>
                    )}
                </Form>
            </div>
        </>
    );
}

BusinessSettings.layout = {
    breadcrumbs: [
        {
            title: t('Business settings'),
            href: editBusiness(),
        },
    ],
};
