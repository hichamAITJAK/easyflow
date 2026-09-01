import type { Errors } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Info } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import {
    buildInitialValues,
    PlanForm,
} from '@/components/super-admin/plan-form';
import type { PlanFormValues } from '@/components/super-admin/plan-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    index as plansIndex,
    update as updatePlan,
} from '@/routes/super-admin/plans';
import type { CatalogPlan } from '@/types';

export default function SuperAdminPlansEdit({
    plan,
    limitKeys,
    subscriptionsCount,
}: {
    plan: CatalogPlan;
    limitKeys: string[];
    subscriptionsCount: number;
}) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const handleSubmit = (values: PlanFormValues) => {
        setProcessing(true);
        setErrors({});

        router.patch(updatePlan(plan.id).url, values, {
            preserveScroll: true,
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <SuperAdminLayout>
            <Head title={`Edit ${plan.name}`} />

            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <Link
                        href={plansIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Go back to plans
                    </Link>
                </div>

                <Heading
                    title={`Edit ${plan.name}`}
                    description="Changes apply to future subscriptions."
                />

                {subscriptionsCount > 0 && (
                    <Alert>
                        <Info />
                        <AlertDescription>
                            {subscriptionsCount}{' '}
                            {subscriptionsCount === 1
                                ? 'subscription has'
                                : 'subscriptions have'}{' '}
                            been sold on this plan. Their price and limits were
                            recorded at activation, so editing here won't change
                            what those tenants already bought.
                        </AlertDescription>
                    </Alert>
                )}

                <PlanForm
                    initial={buildInitialValues({ plan, limitKeys })}
                    limitKeys={limitKeys}
                    errors={errors}
                    processing={processing}
                    submitLabel="Save changes"
                    onSubmit={handleSubmit}
                />
            </div>
        </SuperAdminLayout>
    );
}
