import type { Errors } from '@inertiajs/core';
import { Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { limitLabel } from '@/lib/plan-limits';
import { index as plansIndex } from '@/routes/super-admin/plans';
import type { PlanLimits } from '@/types';

export type PlanFormValues = {
    name: string;
    slug: string;
    price: string;
    currency: string;
    duration_days: string;
    is_active: boolean;
    limits: Record<string, string>;
};

export function buildInitialValues({
    plan,
    limitKeys,
    defaultCurrency = 'MAD',
}: {
    plan?: {
        name: string;
        slug: string;
        price: string;
        currency: string;
        duration_days: number;
        limits: PlanLimits | null;
        is_active: boolean;
    };
    limitKeys: string[];
    defaultCurrency?: string;
}): PlanFormValues {
    return {
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        // The decimal cast gives "49.00"; showing that as-is is right for
        // an editable price field.
        price: plan?.price ?? '',
        currency: plan?.currency ?? defaultCurrency,
        duration_days: String(plan?.duration_days ?? 30),
        is_active: plan?.is_active ?? true,
        limits: Object.fromEntries(
            limitKeys.map((key) => [
                key,
                plan?.limits?.[key] == null ? '' : String(plan.limits[key]),
            ]),
        ),
    };
}

/**
 * Shared create/edit form. Limits are one labelled field per known key
 * rather than a JSON blob: a mistyped key would be stored happily and then
 * silently never enforced, since Subscription::limit() treats an unknown
 * key as unlimited.
 */
export function PlanForm({
    initial,
    limitKeys,
    errors,
    processing,
    submitLabel,
    onSubmit,
}: {
    initial: PlanFormValues;
    limitKeys: string[];
    errors: Errors;
    processing: boolean;
    submitLabel: string;
    onSubmit: (values: PlanFormValues) => void;
}) {
    const [values, setValues] = useState<PlanFormValues>(initial);

    const update = (changes: Partial<PlanFormValues>) =>
        setValues((current) => ({ ...current, ...changes }));

    const updateLimit = (key: string, value: string) =>
        setValues((current) => ({
            ...current,
            limits: { ...current.limits, [key]: value },
        }));

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(values);
            }}
            className="space-y-10"
        >
            <div className="grid gap-6 sm:grid-cols-2">
                <Field>
                    <Label htmlFor="name" className="text-sm font-semibold">
                        Plan name <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="name"
                        value={values.name}
                        onChange={(event) =>
                            update({ name: event.target.value })
                        }
                        required
                        autoFocus
                        placeholder="e.g. Growth"
                        className="h-10"
                    />
                    <InputError message={errors.name} />
                </Field>

                <Field>
                    <Label htmlFor="slug" className="text-sm font-semibold">
                        Slug <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="slug"
                        value={values.slug}
                        onChange={(event) =>
                            update({ slug: event.target.value })
                        }
                        required
                        placeholder="growth"
                        className="h-10 font-mono"
                    />
                    <InputError message={errors.slug} />
                </Field>

                <Field>
                    <Label htmlFor="price" className="text-sm font-semibold">
                        Price <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="price"
                        type="number"
                        inputMode="decimal"
                        step="0.01"
                        min="0"
                        value={values.price}
                        onChange={(event) =>
                            update({ price: event.target.value })
                        }
                        required
                        className="h-10"
                    />
                    <InputError message={errors.price} />
                </Field>

                <Field>
                    <Label htmlFor="currency" className="text-sm font-semibold">
                        Currency <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="currency"
                        value={values.currency}
                        onChange={(event) =>
                            update({
                                currency: event.target.value.toUpperCase(),
                            })
                        }
                        required
                        maxLength={3}
                        className="h-10 font-mono uppercase"
                    />
                    <InputError message={errors.currency} />
                </Field>

                <Field>
                    <Label
                        htmlFor="duration_days"
                        className="text-sm font-semibold"
                    >
                        Duration (days){' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="duration_days"
                        type="number"
                        min="1"
                        value={values.duration_days}
                        onChange={(event) =>
                            update({ duration_days: event.target.value })
                        }
                        required
                        className="h-10"
                    />
                    <FieldDescription>
                        How long one paid cycle lasts.
                    </FieldDescription>
                    <InputError message={errors.duration_days} />
                </Field>

                <Field>
                    <Label className="text-sm font-semibold">
                        Availability
                    </Label>
                    <div className="flex h-10 items-center gap-3">
                        <Switch
                            id="is_active"
                            checked={values.is_active}
                            onCheckedChange={(checked) =>
                                update({ is_active: checked })
                            }
                        />
                        <Label
                            htmlFor="is_active"
                            className="font-normal text-muted-foreground"
                        >
                            {values.is_active
                                ? 'Offered to tenants'
                                : 'Hidden from tenants'}
                        </Label>
                    </div>
                    <InputError message={errors.is_active} />
                </Field>
            </div>

            <div>
                <div className="mb-2 border-b pb-2">
                    <h2 className="text-lg font-semibold tracking-tight">
                        Limits
                    </h2>
                </div>
                <p className="mb-6 text-xs text-muted-foreground">
                    Leave a field blank for unlimited. These ceilings are copied
                    onto a subscription when it is activated, so changing them
                    here affects future cycles, not ones already sold.
                </p>

                <div className="grid gap-6 sm:grid-cols-2">
                    {limitKeys.map((key) => (
                        <Field key={key}>
                            <Label
                                htmlFor={`limit-${key}`}
                                className="text-sm font-semibold"
                            >
                                {limitLabel(key)}
                            </Label>
                            <Input
                                id={`limit-${key}`}
                                type="number"
                                min="0"
                                value={values.limits[key] ?? ''}
                                onChange={(event) =>
                                    updateLimit(key, event.target.value)
                                }
                                placeholder="Unlimited"
                                className="h-10"
                            />
                            <InputError message={errors[`limits.${key}`]} />
                        </Field>
                    ))}
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t pt-6">
                <Button type="button" variant="outline" asChild>
                    <Link href={plansIndex()}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    className="gap-2 px-5"
                >
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
