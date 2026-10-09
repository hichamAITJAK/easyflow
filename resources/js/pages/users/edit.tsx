import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ConfirmationAgentForm } from '@/components/confirmation-agent-form';
import { CreativesEditorForm } from '@/components/creatives-editor-form';
import { FulfilmentAgentForm } from '@/components/fulfilment-agent-form';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as usersIndex } from '@/routes/users';
import type { Product, Store, User } from '@/types';
import type { PerformanceMetric, PerformanceTargetPeriod } from '@/types/agent';

export default function UsersEdit({
    user,
    stores,
    products,
    avatarOptions,
    performanceDefaults,
}: {
    user: User;
    stores: Store[];
    products: Product[];
    avatarOptions: string[];
    performanceDefaults: Record<PerformanceMetric, number> & {
        period: PerformanceTargetPeriod;
    };
}) {
    const { t } = useTranslation();

    const isConfirmation = user.role === 'confirmation_agent';
    const isEditor = user.role === 'creatives_editor';

    return (
        <>
            <Head title={`Edit ${user.name}`} />

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
                    <div className="flex items-center gap-3">
                        <Heading
                            title={`Edit ${user.name}`}
                            description={
                                isConfirmation
                                    ? 'Update confirmation agent profile, pay structure, store/product scope, and daily targets.'
                                    : isEditor
                                      ? 'Update the creatives editor profile and credentials.'
                                      : 'Update fulfilment agent profile, pay rules, and warehouse status.'
                            }
                        />
                        <Badge variant="outline" className="mt-1 self-start">
                            {isConfirmation
                                ? t('Confirmation Agent')
                                : isEditor
                                  ? t('Creatives Editor')
                                  : t('Fulfilment Agent')}
                        </Badge>
                    </div>
                </div>

                {isConfirmation ? (
                    <ConfirmationAgentForm
                        user={user}
                        stores={stores}
                        products={products}
                        avatarOptions={avatarOptions}
                        performanceDefaults={performanceDefaults}
                        onSuccess={() => router.get(usersIndex())}
                        onCancel={() => router.get(usersIndex())}
                    />
                ) : isEditor ? (
                    <CreativesEditorForm
                        user={user}
                        avatarOptions={avatarOptions}
                        onSuccess={() => router.get(usersIndex())}
                        onCancel={() => router.get(usersIndex())}
                    />
                ) : (
                    <FulfilmentAgentForm
                        stores={stores}
                        user={user}
                        avatarOptions={avatarOptions}
                        onSuccess={() => router.get(usersIndex())}
                        onCancel={() => router.get(usersIndex())}
                    />
                )}
            </div>
        </>
    );
}

UsersEdit.layout = {
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
            title: 'Edit agent',
            href: '#',
        },
    ],
};
