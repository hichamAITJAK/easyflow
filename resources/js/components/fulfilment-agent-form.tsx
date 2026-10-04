import { Form } from '@inertiajs/react';
import { IdCard, Wallet } from 'lucide-react';
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
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { CommissionPaymentMode, SalaryPeriod, User } from '@/types';

export function FulfilmentAgentForm({
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
                    {paymentMode === 'salary' && (
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
                                'Choose fixed periodic salary or per-parcel scanning pay in the warehouse.',
                            )}
                            badge={
                                <SectionBadge>
                                    {paymentMode === 'salary'
                                        ? 'Salary Mode'
                                        : 'Per Parcel Pay'}
                                </SectionBadge>
                            }
                        >
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                            </div>
                            <InputError message={errors.payment_mode} />

                            <div className="border-t pt-4">
                                {paymentMode === 'salary' ? (
                                    <SalaryFields
                                        amount={salaryAmount}
                                        onAmountChange={setSalaryAmount}
                                        period={salaryPeriod}
                                        onPeriodChange={setSalaryPeriod}
                                        errors={errors}
                                    />
                                ) : (
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
