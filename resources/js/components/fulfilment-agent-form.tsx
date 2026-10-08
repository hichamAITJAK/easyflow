import { Form } from '@inertiajs/react';
import { IdCard, Store as StoreIcon, Wallet } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { AvatarPanel } from '@/components/agent-form/avatar-panel';
import { ChoiceCard } from '@/components/agent-form/choice-card';
import { FormActionBar } from '@/components/agent-form/form-action-bar';
import {
    FormSection,
    SectionBadge,
} from '@/components/agent-form/form-section';
import { IdentityFields } from '@/components/agent-form/identity-fields';
import { SalaryFields } from '@/components/agent-form/salary-fields';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { CommissionPaymentMode, SalaryPeriod, Store, User } from '@/types';

export function FulfilmentAgentForm({
    user,
    stores = [],
    avatarOptions = [],
    onSuccess,
    onCancel,
    className,
}: {
    user?: User | null;
    stores?: Store[];
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

    const existingRule = user?.commission_rules?.[0] ?? null;

    const [paymentMode, setPaymentMode] = useState<CommissionPaymentMode>(
        existingRule?.payment_mode ?? 'commission',
    );
    const [salaryAmount, setSalaryAmount] = useState(
        existingRule?.salary_amount ?? '',
    );
    const [salaryPeriod, setSalaryPeriod] = useState<SalaryPeriod>(
        existingRule?.salary_period ?? 'monthly',
    );
    const [amount, setAmount] = useState(existingRule?.amount ?? '');

    const [storeScope, setStoreScope] = useState<'all' | 'selected'>(
        user?.agent_scopes?.some((scope) => scope.store_id !== null)
            ? 'selected'
            : 'all',
    );
    const [selectedStoreIds, setSelectedStoreIds] = useState<string[]>(() =>
        user?.agent_scopes
            ? user.agent_scopes
                  .filter((scope) => scope.store_id !== null)
                  .map((scope) => String(scope.store_id))
            : [],
    );
    const storeComboboxOptions = stores.map((store) => ({
        value: String(store.id),
        label: store.name,
    }));

    // "Salary + per parcel" pays both halves, so each half shows whenever
    // the chosen mode includes it.
    const paysSalary = paymentMode !== 'commission';
    const paysCommission = paymentMode !== 'salary';

    return (
        <Form
            {...formProps}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className={cn('flex min-h-0 flex-1 flex-col', className)}
        >
            {({ processing, errors }) => (
                <>
                    {/* Hidden fields guaranteed to always submit */}
                    <input type="hidden" name="role" value="fulfilment_agent" />
                    <input
                        type="hidden"
                        name="payment_mode"
                        value={paymentMode}
                    />
                    {paysSalary && (
                        <input
                            type="hidden"
                            name="salary_period"
                            value={salaryPeriod}
                        />
                    )}

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

                        <FormSection
                            icon={Wallet}
                            title={t('Compensation Structure')}
                            description={t(
                                'Choose a fixed periodic salary, per-parcel scanning pay in the warehouse, or both.',
                            )}
                            badge={
                                <SectionBadge>
                                    {paymentMode === 'salary'
                                        ? t('Salary Mode')
                                        : paymentMode === 'commission'
                                          ? t('Per Parcel Pay')
                                          : t('Salary + Per Parcel')}
                                </SectionBadge>
                            }
                        >
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <ChoiceCard
                                    accent="amber"
                                    selected={paymentMode === 'salary'}
                                    onSelect={() => setPaymentMode('salary')}
                                    title={t('Fixed Salary')}
                                    description={t(
                                        'Fixed periodic compensation (e.g. 3,000 MAD / month) regardless of parcel scanning volume.',
                                    )}
                                />
                                <ChoiceCard
                                    accent="amber"
                                    selected={paymentMode === 'commission'}
                                    onSelect={() =>
                                        setPaymentMode('commission')
                                    }
                                    title={t('Per Parcel Pay')}
                                    description={t(
                                        'Earn a fixed rate for every parcel prepared and staged for courier dispatch (e.g. 5 MAD / parcel).',
                                    )}
                                />
                                <ChoiceCard
                                    accent="amber"
                                    selected={
                                        paymentMode === 'salary_and_commission'
                                    }
                                    onSelect={() =>
                                        setPaymentMode('salary_and_commission')
                                    }
                                    title={t('Salary + Per Parcel')}
                                    description={t(
                                        'A fixed salary, plus a rate for every parcel prepared on top of it.',
                                    )}
                                />
                            </div>
                            <InputError message={errors.payment_mode} />

                            <div className="space-y-6 border-t pt-4">
                                {paysSalary && (
                                    <SalaryFields
                                        amount={salaryAmount}
                                        onAmountChange={setSalaryAmount}
                                        period={salaryPeriod}
                                        onPeriodChange={setSalaryPeriod}
                                        errors={errors}
                                    />
                                )}
                                {paysCommission && (
                                    <div className="grid max-w-sm gap-1.5">
                                        <Label
                                            htmlFor="amount"
                                            className="text-sm font-semibold"
                                        >
                                            {t('Amount Per Parcel')}
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="amount"
                                                name="amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={amount}
                                                onChange={(event) =>
                                                    setAmount(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="5.00"
                                                className="h-10 pr-12"
                                            />
                                            <span className="absolute top-1/2 right-3 -translate-y-1/2 text-xs font-semibold text-muted-foreground">
                                                MAD
                                            </span>
                                        </div>
                                        <InputError message={errors.amount} />
                                    </div>
                                )}
                            </div>
                        </FormSection>

                        <FormSection
                            icon={StoreIcon}
                            title={t('Store Assignment Scope')}
                            description={t(
                                'Restrict which stores this fulfilment agent handles: their parcels, products and scans.',
                            )}
                            badge={
                                storeScope === 'selected' && (
                                    <SectionBadge>
                                        {t('Scoped Access')}
                                    </SectionBadge>
                                )
                            }
                        >
                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <ChoiceCard
                                        accent="amber"
                                        selected={storeScope === 'all'}
                                        onSelect={() => setStoreScope('all')}
                                        title={t('All Stores')}
                                        description={t(
                                            'Handles parcels from every current and future connected store.',
                                        )}
                                    />
                                    <ChoiceCard
                                        accent="amber"
                                        selected={storeScope === 'selected'}
                                        onSelect={() =>
                                            setStoreScope('selected')
                                        }
                                        title={t('Specific Stores')}
                                        description={t(
                                            'Limit parcels, products and scans to specific stores only.',
                                        )}
                                    />
                                </div>

                                {storeScope === 'selected' && (
                                    <div className="rounded-md border p-4">
                                        <Label className="mb-2 block text-xs font-medium">
                                            {t('Select Assigned Stores')}
                                        </Label>
                                        <MultiCombobox
                                            options={storeComboboxOptions}
                                            value={selectedStoreIds}
                                            onChange={setSelectedStoreIds}
                                            placeholder={t('Choose stores...')}
                                        />
                                        {selectedStoreIds.map((storeId) => (
                                            <input
                                                key={storeId}
                                                type="hidden"
                                                name="store_ids[]"
                                                value={storeId}
                                            />
                                        ))}
                                        <InputError
                                            message={errors.store_ids}
                                        />
                                    </div>
                                )}
                            </div>
                        </FormSection>
                    </div>

                    <FormActionBar
                        processing={processing}
                        isEditing={isEditing}
                        createLabel="Add Fulfilment Agent"
                        onCancel={onCancel}
                    />
                </>
            )}
        </Form>
    );
}
