import type { Errors } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Building2, Loader2, UserCog } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    index as businessesIndex,
    store as storeBusiness,
} from '@/routes/super-admin/businesses';
import type { BusinessStatus } from '@/types';

type FormState = {
    business_name: string;
    business_status: BusinessStatus;
    admin_name: string;
    admin_email: string;
    admin_phone: string;
    admin_password: string;
    admin_password_confirmation: string;
};

const BLANK_FORM: FormState = {
    business_name: '',
    business_status: 'active',
    admin_name: '',
    admin_email: '',
    admin_phone: '',
    admin_password: '',
    admin_password_confirmation: '',
};

export default function SuperAdminBusinessesCreate() {
    const [form, setForm] = useState<FormState>(BLANK_FORM);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const update = (changes: Partial<FormState>) =>
        setForm((current) => ({ ...current, ...changes }));

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        router.post(
            storeBusiness().url,
            {
                business_name: form.business_name,
                business_status: form.business_status,
                admin_name: form.admin_name,
                admin_email: form.admin_email,
                admin_phone: form.admin_phone || null,
                admin_password: form.admin_password,
                admin_password_confirmation: form.admin_password_confirmation,
            },
            {
                preserveScroll: true,
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SuperAdminLayout>
            <Head title="Add Business" />

            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <Link
                        href={businessesIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Go back to businesses
                    </Link>
                </div>

                <Heading
                    title="Add business"
                    description="Onboard a new tenant and create its first admin account."
                />

                <form onSubmit={handleSubmit} className="space-y-10">
                    <div>
                        <div className="mb-6 flex items-center gap-2 border-b pb-2">
                            <Building2 className="size-5 text-muted-foreground" />
                            <h2 className="text-lg font-semibold tracking-tight">
                                Business information
                            </h2>
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field>
                                <Label
                                    htmlFor="business_name"
                                    className="text-sm font-semibold"
                                >
                                    Business name{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="business_name"
                                    value={form.business_name}
                                    onChange={(event) =>
                                        update({
                                            business_name: event.target.value,
                                        })
                                    }
                                    required
                                    autoFocus
                                    placeholder="e.g. Heaney Group"
                                    className="h-10"
                                />
                                <InputError message={errors.business_name} />
                            </Field>

                            <Field>
                                <Label
                                    htmlFor="business_status"
                                    className="text-sm font-semibold"
                                >
                                    Status
                                </Label>
                                <Select
                                    value={form.business_status}
                                    onValueChange={(value) =>
                                        update({
                                            business_status:
                                                value as BusinessStatus,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="business_status"
                                        className="h-10 w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="suspended">
                                            Suspended
                                        </SelectItem>
                                        <SelectItem value="cancelled">
                                            Cancelled
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <FieldDescription>
                                    Suspended or cancelled businesses can be
                                    onboarded ahead of time and activated later.
                                </FieldDescription>
                                <InputError message={errors.business_status} />
                            </Field>
                        </div>
                    </div>

                    <div>
                        <div className="mb-6 flex items-center gap-2 border-b pb-2">
                            <UserCog className="size-5 text-muted-foreground" />
                            <h2 className="text-lg font-semibold tracking-tight">
                                Admin user
                            </h2>
                        </div>

                        <p className="-mt-4 mb-6 text-xs text-muted-foreground">
                            This person gets full access to the business's
                            workspace — stores, team, orders, and billing.
                        </p>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field>
                                <Label
                                    htmlFor="admin_name"
                                    className="text-sm font-semibold"
                                >
                                    Full name{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="admin_name"
                                    value={form.admin_name}
                                    onChange={(event) =>
                                        update({
                                            admin_name: event.target.value,
                                        })
                                    }
                                    required
                                    placeholder="e.g. Amina Tazi"
                                    className="h-10"
                                />
                                <InputError message={errors.admin_name} />
                            </Field>

                            <Field>
                                <Label
                                    htmlFor="admin_phone"
                                    className="text-sm font-semibold"
                                >
                                    Phone
                                </Label>
                                <Input
                                    id="admin_phone"
                                    type="tel"
                                    value={form.admin_phone}
                                    onChange={(event) =>
                                        update({
                                            admin_phone: event.target.value,
                                        })
                                    }
                                    placeholder="e.g. +212 6 00 00 00 00"
                                    className="h-10"
                                />
                                <InputError message={errors.admin_phone} />
                            </Field>

                            <Field className="sm:col-span-2">
                                <Label
                                    htmlFor="admin_email"
                                    className="text-sm font-semibold"
                                >
                                    Email{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="admin_email"
                                    type="email"
                                    value={form.admin_email}
                                    onChange={(event) =>
                                        update({
                                            admin_email: event.target.value,
                                        })
                                    }
                                    required
                                    autoComplete="off"
                                    placeholder="admin@business.com"
                                    className="h-10"
                                />
                                <FieldDescription>
                                    They'll sign in with this address.
                                </FieldDescription>
                                <InputError message={errors.admin_email} />
                            </Field>

                            <Field>
                                <Label
                                    htmlFor="admin_password"
                                    className="text-sm font-semibold"
                                >
                                    Temporary password{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <PasswordInput
                                    id="admin_password"
                                    value={form.admin_password}
                                    onChange={(event) =>
                                        update({
                                            admin_password: event.target.value,
                                        })
                                    }
                                    required
                                    autoComplete="new-password"
                                />
                                <FieldDescription>
                                    Share this with them directly — they can
                                    change it after signing in.
                                </FieldDescription>
                                <InputError message={errors.admin_password} />
                            </Field>

                            <Field>
                                <Label
                                    htmlFor="admin_password_confirmation"
                                    className="text-sm font-semibold"
                                >
                                    Confirm password{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <PasswordInput
                                    id="admin_password_confirmation"
                                    value={form.admin_password_confirmation}
                                    onChange={(event) =>
                                        update({
                                            admin_password_confirmation:
                                                event.target.value,
                                        })
                                    }
                                    required
                                    autoComplete="new-password"
                                />
                            </Field>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t pt-6">
                        <Button type="button" variant="outline" asChild>
                            <Link href={businessesIndex()}>Cancel</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="gap-2 px-5"
                        >
                            {processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Onboard business
                        </Button>
                    </div>
                </form>
            </div>
        </SuperAdminLayout>
    );
}
