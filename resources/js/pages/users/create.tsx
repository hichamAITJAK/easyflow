import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, IdCard, Package } from 'lucide-react';
import { useState } from 'react';
import { ConfirmationAgentForm } from '@/components/confirmation-agent-form';
import { FulfilmentAgentForm } from '@/components/fulfilment-agent-form';
import Heading from '@/components/heading';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as usersIndex } from '@/routes/users';
import type { Product, Store } from '@/types';
import type { PerformanceMetric, PerformanceTargetPeriod } from '@/types/agent';

export default function UsersCreate({
    role: initialRole,
    stores,
    products,
    avatarOptions,
    performanceDefaults,
}: {
    role: 'confirmation_agent' | 'fulfilment_agent';
    stores: Store[];
    products: Product[];
    avatarOptions: string[];
    performanceDefaults: Record<PerformanceMetric, number> & {
        period: PerformanceTargetPeriod;
    };
}) {
    const { t } = useTranslation();

    const [role, setRole] = useState<'confirmation_agent' | 'fulfilment_agent'>(
        initialRole,
    );

    return (
        <>
            <Head title={t('Add Team Member')} />

            <div className="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
                <div>
                    <Link
                        href={usersIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to team')}
                    </Link>
                </div>

                <div className="flex flex-col justify-between gap-4 border-b pb-6 sm:flex-row sm:items-center">
                    <Heading
                        title={t('Add Team Member')}
                        description={t(
                            'Create a new agent account and configure their access permissions and pay structure.',
                        )}
                    />

                    <Tabs
                        value={role}
                        onValueChange={(val) =>
                            setRole(
                                val as
                                    'confirmation_agent' | 'fulfilment_agent',
                            )
                        }
                    >
                        <TabsList className="grid w-full grid-cols-2 sm:w-auto">
                            <TabsTrigger
                                value="confirmation_agent"
                                className="flex items-center gap-2 px-4"
                            >
                                <IdCard className="size-4" />
                                <span>{t('Confirmation Agent')}</span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="fulfilment_agent"
                                className="flex items-center gap-2 px-4"
                            >
                                <Package className="size-4" />
                                <span>{t('Fulfilment Agent')}</span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>

                {role === 'confirmation_agent' ? (
                    <ConfirmationAgentForm
                        stores={stores}
                        products={products}
                        avatarOptions={avatarOptions}
                        performanceDefaults={performanceDefaults}
                        onSuccess={() => router.get(usersIndex())}
                        onCancel={() => router.get(usersIndex())}
                    />
                ) : (
                    <FulfilmentAgentForm
                        stores={stores}
                        avatarOptions={avatarOptions}
                        onSuccess={() => router.get(usersIndex())}
                        onCancel={() => router.get(usersIndex())}
                    />
                )}
            </div>
        </>
    );
}

UsersCreate.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Team',
            href: usersIndex(),
        },
        {
            title: 'Add agent',
            href: '/users/create',
        },
    ],
};
