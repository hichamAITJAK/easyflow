import type { Errors } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import {
    buildInitialValues,
    PlanForm,
} from '@/components/super-admin/plan-form';
import type { PlanFormValues } from '@/components/super-admin/plan-form';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    index as plansIndex,
    store as storePlan,
} from '@/routes/super-admin/plans';

export default function SuperAdminPlansCreate({
    limitKeys,
    defaultCurrency,
}: {
    limitKeys: string[];
    defaultCurrency: string;
}) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const handleSubmit = (values: PlanFormValues) => {
        setProcessing(true);
        setErrors({});

        router.post(storePlan().url, values, {
            preserveScroll: true,
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <SuperAdminLayout>
            <Head title="New plan" />

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
                    title="New plan"
                    description="A subscription tier tenants can pay for."
                />

                <PlanForm
                    initial={buildInitialValues({ limitKeys, defaultCurrency })}
                    limitKeys={limitKeys}
                    errors={errors}
                    processing={processing}
                    submitLabel="Create plan"
                    onSubmit={handleSubmit}
                />
            </div>
        </SuperAdminLayout>
    );
}
