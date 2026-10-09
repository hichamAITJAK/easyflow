import { Form } from '@inertiajs/react';
import { IdCard } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { AvatarPanel } from '@/components/agent-form/avatar-panel';
import { FormActionBar } from '@/components/agent-form/form-action-bar';
import { FormSection } from '@/components/agent-form/form-section';
import { IdentityFields } from '@/components/agent-form/identity-fields';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

/**
 * A creatives editor is a plain team account: identity and credentials
 * only. Their pay is per validated content request (the Creatives
 * module), so there is no salary/commission block and no store scope.
 */
export function CreativesEditorForm({
    user,
    avatarOptions = [],
    onSuccess,
    onCancel,
    className,
}: {
    user?: User | null;
    avatarOptions?: string[];
    onSuccess?: () => void;
    onCancel?: () => void;
    className?: string;
}) {
    const { t } = useTranslation();

    const isEditing = Boolean(user);
    const formProps = isEditing
        ? UserController.update.form(user!.id)
        : UserController.store.form();

    return (
        <Form
            {...formProps}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className={cn('flex min-h-0 flex-1 flex-col', className)}
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="role" value="creatives_editor" />

                    <div className="flex-1 divide-y px-6 md:px-8">
                        <FormSection
                            icon={IdCard}
                            title={t('General & Profile')}
                            description={t(
                                'Identity, contact information, and account credentials.',
                            )}
                        >
                            <div className="grid gap-6 rounded-md border p-5 md:grid-cols-[16rem_1fr]">
                                <AvatarPanel
                                    avatarUrl={user?.avatar}
                                    avatarOptions={avatarOptions}
                                />
                                <div className="md:border-l md:pl-6">
                                    <IdentityFields
                                        user={user}
                                        isEditing={isEditing}
                                        errors={errors}
                                    />
                                </div>
                            </div>
                        </FormSection>
                    </div>

                    <FormActionBar
                        processing={processing}
                        isEditing={isEditing}
                        createLabel={t('Add Creatives Editor')}
                        onCancel={onCancel}
                    />
                </>
            )}
        </Form>
    );
}
