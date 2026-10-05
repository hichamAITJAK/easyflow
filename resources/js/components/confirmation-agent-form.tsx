import { Form } from '@inertiajs/react';
import {
    Check,
    ChevronsUpDown,
    Goal,
    IdCard,
    Plus,
    Store as StoreIcon,
    TrendingUp,
    Truck,
    Wallet,
    X,
} from 'lucide-react';
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
import { KpiTargetCard } from '@/components/agent-form/kpi-target-card';
import { SalaryFields } from '@/components/agent-form/salary-fields';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    CommissionAmountType,
    CommissionPaymentMode,
    PerformanceMetric,
    PerformanceTargetPeriod,
    Product,
    SalaryPeriod,
    Store,
    User,
} from '@/types';

type OverrideScopeType = 'store' | 'product';

type OverrideRow = {
    key: string;
    scopeType: OverrideScopeType;
    scopeId: string;
    amountType: CommissionAmountType;
    amount: string;
};

const triggerStatusOptions = [
    { value: 'confirmed', label: 'Order confirmed' },
    { value: 'delivered', label: 'Order delivered' },
];

type TargetState = {
    target_percentage: string;
    /** Rolling window this target is judged over. */
    period: PerformanceTargetPeriod;
    /** Blank means no bonus — never 0, which would read as "pays nothing". */
    bonus_amount: string;
};

const PERIOD_OPTIONS: { value: PerformanceTargetPeriod; label: string }[] = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];

export function ConfirmationAgentForm({
    user,
    stores,
    products,
    avatarOptions = [],
    performanceDefaults,
    onSuccess,
    onCancel,
    className,
}: {
    user?: User | null;
    stores: Store[];
    products: Product[];
    avatarOptions?: string[];
    /**
     * The business-wide target each metric falls back to when this agent
     * has no override — what the "Default (…)" labels state. Comes from the
     * business's own PerformanceTarget rows, so the label always matches
     * what the evaluator will actually measure against.
     */
    performanceDefaults?: Record<PerformanceMetric, number> & {
        period?: PerformanceTargetPeriod;
    };
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

    // "Salary + commission" pays both halves, so each half shows whenever
    // the chosen mode includes it.
    const paysSalary = paymentMode !== 'commission';
    const paysCommission = paymentMode !== 'salary';
    const [salaryAmount, setSalaryAmount] = useState(
        existingRule?.salary_amount ?? '',
    );
    const [salaryPeriod, setSalaryPeriod] = useState<SalaryPeriod>(
        existingRule?.salary_period ?? 'monthly',
    );
    const [amountType, setAmountType] = useState<CommissionAmountType>(
        existingRule?.amount_type ?? 'fixed',
    );
    const [amount, setAmount] = useState(existingRule?.amount ?? '');
    const [triggerStatus, setTriggerStatus] = useState(
        existingRule?.trigger_status ?? 'confirmed',
    );

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

    const [productScope, setProductScope] = useState<'all' | 'selected'>(
        user?.agent_scopes?.some((scope) => scope.product_id !== null)
            ? 'selected'
            : 'all',
    );
    const [selectedProductIds, setSelectedProductIds] = useState<string[]>(
        () =>
            user?.agent_scopes
                ? user.agent_scopes
                      .filter((scope) => scope.product_id !== null)
                      .map((scope) => String(scope.product_id))
                : [],
    );

    const [overrideRows, setOverrideRows] = useState<OverrideRow[]>(() => {
        if (!user?.commission_rules || user.commission_rules.length <= 1) {
            return [];
        }

        return user.commission_rules.slice(1).map((rule, idx) => {
            const scopeType: OverrideScopeType =
                rule.product_id !== null ? 'product' : 'store';
            const scopeId =
                scopeType === 'product'
                    ? String(rule.product_id)
                    : String(rule.store_id);

            return {
                key: `override-init-${idx}`,
                scopeType,
                scopeId,
                amountType: rule.amount_type ?? 'fixed',
                amount: rule.amount ?? '',
            };
        });
    });

    const addOverrideRow = () => {
        setOverrideRows((current) => [
            ...current,
            {
                key: `override-${Date.now()}-${Math.random()}`,
                scopeType: 'store',
                scopeId: stores[0] ? String(stores[0].id) : '',
                amountType: 'fixed',
                amount: '',
            },
        ]);
    };

    const [removingOverrideKey, setRemovingOverrideKey] = useState<
        string | null
    >(null);

    const [openScopePopoverKey, setOpenScopePopoverKey] = useState<
        string | null
    >(null);

    const removeOverrideRow = (key: string) => {
        setOverrideRows((current) => current.filter((row) => row.key !== key));
        setRemovingOverrideKey(null);
    };

    const updateOverrideRow = (
        key: string,
        updates: Partial<Omit<OverrideRow, 'key'>>,
    ) => {
        setOverrideRows((current) =>
            current.map((row) => {
                if (row.key !== key) {
                    return row;
                }

                const next = { ...row, ...updates };

                if (updates.scopeType && updates.scopeType !== row.scopeType) {
                    next.scopeId =
                        updates.scopeType === 'store'
                            ? stores[0]
                                ? String(stores[0].id)
                                : ''
                            : products[0]
                              ? String(products[0].id)
                              : '';
                }

                return next;
            }),
        );
    };

    const confirmationRateMetric = user?.performance_targets?.find(
        (target) => target.metric === 'confirmation_rate',
    );
    const deliverySuccessMetric = user?.performance_targets?.find(
        (target) => target.metric === 'delivery_success_rate',
    );

    const [overrideConfirmationRate, setOverrideConfirmationRate] = useState(
        Boolean(confirmationRateMetric),
    );
    const [overrideDeliverySuccess, setOverrideDeliverySuccess] = useState(
        Boolean(deliverySuccessMetric),
    );

    // Server-provided when available; the literals only cover a caller
    // that renders this form without the prop.
    const defaults: Record<PerformanceMetric, number> & {
        period: PerformanceTargetPeriod;
    } = {
        confirmation_rate: 80,
        delivery_success_rate: 90,
        period: 'weekly',
        ...performanceDefaults,
    };

    const [targets, setTargets] = useState<
        Record<PerformanceMetric, TargetState>
    >({
        confirmation_rate: {
            target_percentage: confirmationRateMetric
                ? String(confirmationRateMetric.target_percentage)
                : String(defaults.confirmation_rate),
            period: confirmationRateMetric?.period ?? defaults.period,
            bonus_amount: confirmationRateMetric?.bonus_amount
                ? String(confirmationRateMetric.bonus_amount)
                : '',
        },
        delivery_success_rate: {
            target_percentage: deliverySuccessMetric
                ? String(deliverySuccessMetric.target_percentage)
                : String(defaults.delivery_success_rate),
            period: deliverySuccessMetric?.period ?? defaults.period,
            bonus_amount: deliverySuccessMetric?.bonus_amount
                ? String(deliverySuccessMetric.bonus_amount)
                : '',
        },
    });

    const updateTarget = (
        metric: PerformanceMetric,
        field: keyof TargetState,
        value: string,
    ) => {
        setTargets((current) => ({
            ...current,
            [metric]: { ...current[metric], [field]: value },
        }));
    };

    const storeComboboxOptions = stores.map((store) => ({
        value: String(store.id),
        label: store.name,
    }));
    const productComboboxOptions = products.map((product) => ({
        value: String(product.id),
        label: product.name,
    }));

    const removingOverride = overrideRows.find(
        (row) => row.key === removingOverrideKey,
    );
    const removingOverrideLabel = removingOverride
        ? ((removingOverride.scopeType === 'store'
              ? stores.find(
                    (store) => String(store.id) === removingOverride.scopeId,
                )?.name
              : products.find(
                    (product) =>
                        String(product.id) === removingOverride.scopeId,
                )?.name) ?? 'this item')
        : null;

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
                    <input
                        type="hidden"
                        name="role"
                        value="confirmation_agent"
                    />
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
                    {paysCommission && (
                        <>
                            <input
                                type="hidden"
                                name="amount_type"
                                value={amountType}
                            />
                            <input
                                type="hidden"
                                name="trigger_status"
                                value={triggerStatus}
                            />
                        </>
                    )}
                    <input
                        type="hidden"
                        name="override_confirmation_rate_target"
                        value={overrideConfirmationRate ? '1' : '0'}
                    />
                    <input
                        type="hidden"
                        name="override_delivery_success_target"
                        value={overrideDeliverySuccess ? '1' : '0'}
                    />

                    <div className="flex-1 space-y-6 px-6 py-6 md:px-8">
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
                            icon={StoreIcon}
                            title={t('Store & Product Assignment Scope')}
                            description={t(
                                'Restrict which stores or products this confirmation agent has permission to access and confirm.',
                            )}
                            badge={
                                (storeScope === 'selected' ||
                                    productScope === 'selected') && (
                                    <SectionBadge>
                                        {t('Scoped Access')}
                                    </SectionBadge>
                                )
                            }
                        >
                            <div className="space-y-4">
                                <h4 className="text-sm font-medium">
                                    {t('Store Scope')}
                                </h4>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <ChoiceCard
                                        selected={storeScope === 'all'}
                                        onSelect={() => setStoreScope('all')}
                                        title={t('All Stores')}
                                        description={t(
                                            'Can confirm orders across all current and future connected stores.',
                                        )}
                                    />
                                    <ChoiceCard
                                        selected={storeScope === 'selected'}
                                        onSelect={() =>
                                            setStoreScope('selected')
                                        }
                                        title={t('Specific Stores')}
                                        description={t(
                                            'Limit assignment and order visibility to specific stores only.',
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
                                    </div>
                                )}
                            </div>

                            <div className="space-y-4 border-t pt-6">
                                <h4 className="text-sm font-medium">
                                    {t('Product Scope')}
                                </h4>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <ChoiceCard
                                        selected={productScope === 'all'}
                                        onSelect={() => setProductScope('all')}
                                        title={t('All Products')}
                                        description={t(
                                            'Can confirm orders containing any catalog items.',
                                        )}
                                    />
                                    <ChoiceCard
                                        selected={productScope === 'selected'}
                                        onSelect={() =>
                                            setProductScope('selected')
                                        }
                                        title={t('Specific Products')}
                                        description={t(
                                            'Limit agent to handle only orders that include specific products.',
                                        )}
                                    />
                                </div>

                                {productScope === 'selected' && (
                                    <div className="rounded-md border p-4">
                                        <Label className="mb-2 block text-xs font-medium">
                                            {t('Select Assigned Products')}
                                        </Label>
                                        <MultiCombobox
                                            options={productComboboxOptions}
                                            value={selectedProductIds}
                                            onChange={setSelectedProductIds}
                                            placeholder={t(
                                                'Choose products...',
                                            )}
                                        />
                                        {selectedProductIds.map((productId) => (
                                            <input
                                                key={productId}
                                                type="hidden"
                                                name="product_ids[]"
                                                value={productId}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </FormSection>

                        <FormSection
                            icon={Wallet}
                            title={t('Compensation Structure')}
                            description={t(
                                'Choose whether this agent receives a fixed periodic salary, a commission per confirmed/delivered order, or both.',
                            )}
                            badge={
                                <SectionBadge>
                                    {paymentMode === 'salary'
                                        ? t('Salary Mode')
                                        : paymentMode === 'commission'
                                          ? t('Commission Mode')
                                          : t('Salary + Commission')}
                                </SectionBadge>
                            }
                        >
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <ChoiceCard
                                    selected={paymentMode === 'salary'}
                                    onSelect={() => setPaymentMode('salary')}
                                    title={t('Fixed Salary')}
                                    description={t(
                                        'Fixed periodic compensation (e.g. 3,000 MAD / month) regardless of order volume.',
                                    )}
                                />
                                <ChoiceCard
                                    selected={paymentMode === 'commission'}
                                    onSelect={() =>
                                        setPaymentMode('commission')
                                    }
                                    title={t('Per-Order Commission')}
                                    description={t(
                                        'Earn a fixed fee or percentage for every order successfully confirmed or delivered.',
                                    )}
                                />
                                <ChoiceCard
                                    selected={
                                        paymentMode === 'salary_and_commission'
                                    }
                                    onSelect={() =>
                                        setPaymentMode('salary_and_commission')
                                    }
                                    title={t('Salary + Commission')}
                                    description={t(
                                        'A fixed salary, plus a commission for every order on top of it.',
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
                                    <div className="space-y-6">
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="amount_type"
                                                    className="text-sm font-semibold"
                                                >
                                                    {t('Amount Type')}
                                                </Label>
                                                <Select
                                                    value={amountType}
                                                    onValueChange={(v) =>
                                                        setAmountType(
                                                            v as CommissionAmountType,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="amount_type"
                                                        className="h-10 w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="fixed">
                                                            {t(
                                                                'Fixed amount per order',
                                                            )}
                                                        </SelectItem>
                                                        <SelectItem value="percentage">
                                                            {t(
                                                                'Percentage of order total',
                                                            )}
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={errors.amount_type}
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="amount"
                                                    className="text-sm font-semibold"
                                                >
                                                    {amountType === 'percentage'
                                                        ? t('Percentage Rate')
                                                        : t('Commission Rate')}
                                                </Label>
                                                <InputGroup>
                                                    <InputGroupInput
                                                        id="amount"
                                                        name="amount"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        max={
                                                            amountType ===
                                                            'percentage'
                                                                ? 100
                                                                : undefined
                                                        }
                                                        value={amount}
                                                        onChange={(event) =>
                                                            setAmount(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder={
                                                            amountType ===
                                                            'percentage'
                                                                ? '2.5'
                                                                : '10.00'
                                                        }
                                                    />
                                                    <InputGroupAddon align="inline-end">
                                                        {amountType ===
                                                        'percentage'
                                                            ? '%'
                                                            : 'MAD'}
                                                    </InputGroupAddon>
                                                </InputGroup>
                                                <InputError
                                                    message={errors.amount}
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="trigger_status"
                                                    className="text-sm font-semibold"
                                                >
                                                    {t('Trigger Event')}
                                                </Label>
                                                <Select
                                                    value={triggerStatus}
                                                    onValueChange={(v) =>
                                                        setTriggerStatus(
                                                            v as
                                                                | 'confirmed'
                                                                | 'delivered',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="trigger_status"
                                                        className="h-10 w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {triggerStatusOptions.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {t(
                                                                        option.label,
                                                                    )}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        errors.trigger_status
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <Card className="shadow-none">
                                            <CardContent className="space-y-0">
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <h4 className="text-sm font-medium">
                                                            {t(
                                                                'Store & Product Overrides',
                                                            )}
                                                        </h4>
                                                        <p className="text-xs text-muted-foreground">
                                                            {t(
                                                                'Set custom commission rates for specific stores or products.',
                                                            )}
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={addOverrideRow}
                                                    >
                                                        <Plus className="size-3.5" />
                                                        {t('Add Override')}
                                                    </Button>
                                                </div>

                                                {overrideRows.length > 0 && (
                                                    <div className="mt-4 divide-y rounded-md border">
                                                        {overrideRows.map(
                                                            (row, index) => (
                                                                <div
                                                                    key={
                                                                        row.key
                                                                    }
                                                                    className="grid items-center gap-3 p-3 sm:grid-cols-[110px_1fr_130px_120px_auto]"
                                                                >
                                                                    <Select
                                                                        value={
                                                                            row.scopeType
                                                                        }
                                                                        onValueChange={(
                                                                            v,
                                                                        ) =>
                                                                            updateOverrideRow(
                                                                                row.key,
                                                                                {
                                                                                    scopeType:
                                                                                        v as OverrideScopeType,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <SelectTrigger className="h-8 text-xs">
                                                                            <SelectValue />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            <SelectItem value="store">
                                                                                {t(
                                                                                    'Store',
                                                                                )}
                                                                            </SelectItem>
                                                                            <SelectItem value="product">
                                                                                {t(
                                                                                    'Product',
                                                                                )}
                                                                            </SelectItem>
                                                                        </SelectContent>
                                                                    </Select>

                                                                    <Popover
                                                                        open={
                                                                            openScopePopoverKey ===
                                                                            row.key
                                                                        }
                                                                        onOpenChange={(
                                                                            nextOpen,
                                                                        ) =>
                                                                            setOpenScopePopoverKey(
                                                                                nextOpen
                                                                                    ? row.key
                                                                                    : null,
                                                                            )
                                                                        }
                                                                    >
                                                                        <PopoverTrigger
                                                                            asChild
                                                                        >
                                                                            <Button
                                                                                type="button"
                                                                                variant="outline"
                                                                                role="combobox"
                                                                                aria-expanded={
                                                                                    openScopePopoverKey ===
                                                                                    row.key
                                                                                }
                                                                                className="h-8 w-full justify-between text-xs font-normal"
                                                                            >
                                                                                {(row.scopeType ===
                                                                                'store'
                                                                                    ? storeComboboxOptions
                                                                                    : productComboboxOptions
                                                                                ).find(
                                                                                    (
                                                                                        option,
                                                                                    ) =>
                                                                                        option.value ===
                                                                                        row.scopeId,
                                                                                )
                                                                                    ?.label ??
                                                                                    (row.scopeType ===
                                                                                    'store'
                                                                                        ? t(
                                                                                              'Select store',
                                                                                          )
                                                                                        : t(
                                                                                              'Select product',
                                                                                          ))}
                                                                                <ChevronsUpDown className="opacity-50" />
                                                                            </Button>
                                                                        </PopoverTrigger>
                                                                        <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                                                                            <Command>
                                                                                <CommandInput
                                                                                    placeholder={t(
                                                                                        'Search…',
                                                                                    )}
                                                                                />
                                                                                <CommandList>
                                                                                    <CommandEmpty>
                                                                                        {t(
                                                                                            'No results found.',
                                                                                        )}
                                                                                    </CommandEmpty>
                                                                                    <CommandGroup>
                                                                                        {(row.scopeType ===
                                                                                        'store'
                                                                                            ? storeComboboxOptions
                                                                                            : productComboboxOptions
                                                                                        ).map(
                                                                                            (
                                                                                                option,
                                                                                            ) => (
                                                                                                <CommandItem
                                                                                                    key={
                                                                                                        option.value
                                                                                                    }
                                                                                                    value={
                                                                                                        option.label
                                                                                                    }
                                                                                                    onSelect={() => {
                                                                                                        updateOverrideRow(
                                                                                                            row.key,
                                                                                                            {
                                                                                                                scopeId:
                                                                                                                    option.value,
                                                                                                            },
                                                                                                        );
                                                                                                        setOpenScopePopoverKey(
                                                                                                            null,
                                                                                                        );
                                                                                                    }}
                                                                                                >
                                                                                                    <Check
                                                                                                        className={cn(
                                                                                                            'mr-2',
                                                                                                            row.scopeId ===
                                                                                                                option.value
                                                                                                                ? 'opacity-100'
                                                                                                                : 'opacity-0',
                                                                                                        )}
                                                                                                    />
                                                                                                    {
                                                                                                        option.label
                                                                                                    }
                                                                                                </CommandItem>
                                                                                            ),
                                                                                        )}
                                                                                    </CommandGroup>
                                                                                </CommandList>
                                                                            </Command>
                                                                        </PopoverContent>
                                                                    </Popover>

                                                                    <Select
                                                                        value={
                                                                            row.amountType
                                                                        }
                                                                        onValueChange={(
                                                                            v,
                                                                        ) =>
                                                                            updateOverrideRow(
                                                                                row.key,
                                                                                {
                                                                                    amountType:
                                                                                        v as CommissionAmountType,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <SelectTrigger className="h-8 text-xs">
                                                                            <SelectValue />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            <SelectItem value="fixed">
                                                                                Fixed
                                                                                (MAD)
                                                                            </SelectItem>
                                                                            <SelectItem value="percentage">
                                                                                Percentage
                                                                                (%)
                                                                            </SelectItem>
                                                                        </SelectContent>
                                                                    </Select>

                                                                    <Input
                                                                        type="number"
                                                                        step="0.01"
                                                                        min="0"
                                                                        max={
                                                                            row.amountType ===
                                                                            'percentage'
                                                                                ? 100
                                                                                : undefined
                                                                        }
                                                                        className="h-8 text-xs"
                                                                        value={
                                                                            row.amount
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateOverrideRow(
                                                                                row.key,
                                                                                {
                                                                                    amount: event
                                                                                        .target
                                                                                        .value,
                                                                                },
                                                                            )
                                                                        }
                                                                        placeholder={t(
                                                                            'Amount',
                                                                        )}
                                                                    />

                                                                    <input
                                                                        type="hidden"
                                                                        name={`overrides[${index}][store_id]`}
                                                                        value={
                                                                            row.scopeType ===
                                                                            'store'
                                                                                ? row.scopeId
                                                                                : ''
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`overrides[${index}][product_id]`}
                                                                        value={
                                                                            row.scopeType ===
                                                                            'product'
                                                                                ? row.scopeId
                                                                                : ''
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`overrides[${index}][amount_type]`}
                                                                        value={
                                                                            row.amountType
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`overrides[${index}][amount]`}
                                                                        value={
                                                                            row.amount
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`overrides[${index}][trigger_status]`}
                                                                        value={
                                                                            triggerStatus
                                                                        }
                                                                    />

                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="-m-1.5 size-11 text-muted-foreground hover:text-destructive"
                                                                        onClick={() =>
                                                                            setRemovingOverrideKey(
                                                                                row.key,
                                                                            )
                                                                        }
                                                                    >
                                                                        <X className="size-4" />
                                                                    </Button>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </div>
                                )}
                            </div>
                        </FormSection>

                        <FormSection
                            icon={Goal}
                            title={t('Performance Benchmarks & Targets')}
                            description={t(
                                'Configure custom KPI goals and confirmation quotas for this agent.',
                            )}
                            badge={
                                (overrideConfirmationRate ||
                                    overrideDeliverySuccess) && (
                                    <SectionBadge>
                                        {t('Custom KPI Targets')}
                                    </SectionBadge>
                                )
                            }
                        >
                            <KpiTargetCard
                                icon={TrendingUp}
                                title={t('Target Confirmation Rate')}
                                description={t(
                                    'Percentage of assigned orders expected to be successfully confirmed.',
                                )}
                                defaultLabel={`Default (${defaults.confirmation_rate}%)`}
                                isCustom={overrideConfirmationRate}
                                onToggleCustom={() =>
                                    setOverrideConfirmationRate(
                                        !overrideConfirmationRate,
                                    )
                                }
                                value={
                                    targets.confirmation_rate.target_percentage
                                }
                                onValueChange={(value) =>
                                    updateTarget(
                                        'confirmation_rate',
                                        'target_percentage',
                                        value,
                                    )
                                }
                                quickPicks={['70', '80', '85', '90', '95']}
                                hiddenFields={
                                    <>
                                        <input
                                            type="hidden"
                                            name="targets[0][metric]"
                                            value="confirmation_rate"
                                        />
                                        <input
                                            type="hidden"
                                            name="targets[0][target_percentage]"
                                            value={
                                                targets.confirmation_rate
                                                    .target_percentage
                                            }
                                        />
                                        <div className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2">
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="confirmation_rate_period"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    {t('Measured over')}
                                                </Label>
                                                <Select
                                                    value={
                                                        targets
                                                            .confirmation_rate
                                                            .period
                                                    }
                                                    onValueChange={(value) =>
                                                        updateTarget(
                                                            'confirmation_rate',
                                                            'period',
                                                            value,
                                                        )
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
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="confirmation_rate_bonus"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    Bonus when met (MAD)
                                                </Label>
                                                <Input
                                                    id="confirmation_rate_bonus"
                                                    type="number"
                                                    inputMode="decimal"
                                                    min={0}
                                                    step="0.01"
                                                    placeholder={t('No bonus')}
                                                    value={
                                                        targets
                                                            .confirmation_rate
                                                            .bonus_amount
                                                    }
                                                    onChange={(event) =>
                                                        updateTarget(
                                                            'confirmation_rate',
                                                            'bonus_amount',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <input
                                            type="hidden"
                                            name="targets[0][period]"
                                            value={
                                                targets.confirmation_rate.period
                                            }
                                        />
                                        {targets.confirmation_rate
                                            .bonus_amount !== '' && (
                                            <input
                                                type="hidden"
                                                name="targets[0][bonus_amount]"
                                                value={
                                                    targets.confirmation_rate
                                                        .bonus_amount
                                                }
                                            />
                                        )}
                                    </>
                                }
                            />

                            <KpiTargetCard
                                icon={Truck}
                                title={t('Target Delivery Success Rate')}
                                description={t(
                                    "Percentage of this agent's shipped orders expected to be delivered rather than returned.",
                                )}
                                defaultLabel={`Default (${defaults.delivery_success_rate}%)`}
                                isCustom={overrideDeliverySuccess}
                                onToggleCustom={() =>
                                    setOverrideDeliverySuccess(
                                        !overrideDeliverySuccess,
                                    )
                                }
                                value={
                                    targets.delivery_success_rate
                                        .target_percentage
                                }
                                onValueChange={(value) =>
                                    updateTarget(
                                        'delivery_success_rate',
                                        'target_percentage',
                                        value,
                                    )
                                }
                                quickPicks={['70', '80', '85', '90', '95']}
                                hiddenFields={
                                    <>
                                        <input
                                            type="hidden"
                                            name="targets[1][metric]"
                                            value="delivery_success_rate"
                                        />
                                        <input
                                            type="hidden"
                                            name="targets[1][target_percentage]"
                                            value={
                                                targets.delivery_success_rate
                                                    .target_percentage
                                            }
                                        />
                                        <div className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2">
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="delivery_success_rate_period"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    {t('Measured over')}
                                                </Label>
                                                <Select
                                                    value={
                                                        targets
                                                            .delivery_success_rate
                                                            .period
                                                    }
                                                    onValueChange={(value) =>
                                                        updateTarget(
                                                            'delivery_success_rate',
                                                            'period',
                                                            value,
                                                        )
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
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="delivery_success_rate_bonus"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    Bonus when met (MAD)
                                                </Label>
                                                <Input
                                                    id="delivery_success_rate_bonus"
                                                    type="number"
                                                    inputMode="decimal"
                                                    min={0}
                                                    step="0.01"
                                                    placeholder={t('No bonus')}
                                                    value={
                                                        targets
                                                            .delivery_success_rate
                                                            .bonus_amount
                                                    }
                                                    onChange={(event) =>
                                                        updateTarget(
                                                            'delivery_success_rate',
                                                            'bonus_amount',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <input
                                            type="hidden"
                                            name="targets[1][period]"
                                            value={
                                                targets.delivery_success_rate
                                                    .period
                                            }
                                        />
                                        {targets.delivery_success_rate
                                            .bonus_amount !== '' && (
                                            <input
                                                type="hidden"
                                                name="targets[1][bonus_amount]"
                                                value={
                                                    targets
                                                        .delivery_success_rate
                                                        .bonus_amount
                                                }
                                            />
                                        )}
                                    </>
                                }
                            />
                        </FormSection>
                    </div>

                    <FormActionBar
                        processing={processing}
                        isEditing={isEditing}
                        createLabel="Add Confirmation Agent"
                        onCancel={onCancel}
                    />

                    <AlertDialog
                        open={removingOverrideKey !== null}
                        onOpenChange={(open) =>
                            !open && setRemovingOverrideKey(null)
                        }
                    >
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Remove this override?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    {removingOverride && (
                                        <>
                                            {t('The custom :kind for', {
                                                kind:
                                                    removingOverride.amountType ===
                                                    'percentage'
                                                        ? t('percentage rate')
                                                        : t('fixed amount'),
                                            })}{' '}
                                            <strong>
                                                {removingOverrideLabel}
                                            </strong>{' '}
                                            will be discarded. This can&apos;t
                                            be undone.
                                        </>
                                    )}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>
                                    {t('Cancel')}
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() =>
                                        removingOverrideKey &&
                                        removeOverrideRow(removingOverrideKey)
                                    }
                                >
                                    {t('Remove override')}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </>
            )}
        </Form>
    );
}
