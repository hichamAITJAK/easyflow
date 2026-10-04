import { Head, router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import CourierSettlementController from '@/actions/App/Http/Controllers/Settlements/CourierSettlementController';
import {
    DataTableCard,
    DataTableCardTable,
} from '@/components/data-table/data-table-card';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate, formatDateTime } from '@/lib/format';
import { dashboard } from '@/routes';
import type {
    CourierSettlement,
    CourierSettlementStatus,
    DeliveryAccount,
} from '@/types';

const statusClasses: Record<CourierSettlementStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    reconciled:
        'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    disputed: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
};

function money(value: string | number | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(2)} MAD`;
}

function courierLabel(account: DeliveryAccount): string {
    return account.courier?.name
        ? `${account.courier.name} — ${account.label}`
        : account.label;
}

export default function SettlementsIndex({
    settlements,
    deliveryAccounts,
}: {
    settlements: CourierSettlement[];
    deliveryAccounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const [deliveryAccountId, setDeliveryAccountId] = useState('');
    const [periodStart, setPeriodStart] = useState('');
    const [periodEnd, setPeriodEnd] = useState('');
    const [actualAmount, setActualAmount] = useState('');
    const [notes, setNotes] = useState('');
    const [expectedAmount, setExpectedAmount] = useState<number | null>(null);
    const [loadingExpected, setLoadingExpected] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const canFetchExpected = deliveryAccountId && periodStart && periodEnd;

    useEffect(() => {
        if (!canFetchExpected) {
            return;
        }

        let cancelled = false;
        setLoadingExpected(true);

        const url = `${CourierSettlementController.expected().url}?delivery_account_id=${deliveryAccountId}&period_start=${periodStart}&period_end=${periodEnd}`;

        fetch(url, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data) => {
                if (!cancelled) {
                    setExpectedAmount(data.expected_amount);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingExpected(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [deliveryAccountId, periodStart, periodEnd]);

    const handleReconcile = () => {
        setSubmitting(true);

        router.post(
            CourierSettlementController.reconcile().url,
            {
                delivery_account_id: deliveryAccountId,
                period_start: periodStart,
                period_end: periodEnd,
                actual_amount: actualAmount,
                notes: notes || undefined,
            },
            {
                onSuccess: () => {
                    setDeliveryAccountId('');
                    setPeriodStart('');
                    setPeriodEnd('');
                    setActualAmount('');
                    setNotes('');
                    setExpectedAmount(null);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const handleDispute = (settlement: CourierSettlement) => {
        router.patch(CourierSettlementController.dispute(settlement.id).url);
    };

    return (
        <>
            <Head title={t('Courier settlements')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Courier settlements')}
                    description={t(
                        'Compare what a courier owes you against what they actually paid, for a chosen period.',
                    )}
                />

                <div className="space-y-4 rounded-lg border p-4">
                    <h2 className="text-sm font-medium">
                        {t('Reconcile a period')}
                    </h2>

                    <FieldGroup className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <Field>
                            <FieldLabel>{t('Delivery account')}</FieldLabel>
                            <Select
                                value={deliveryAccountId}
                                onValueChange={setDeliveryAccountId}
                            >
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder={t('Choose an account')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {deliveryAccounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {courierLabel(account)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field>
                            <FieldLabel htmlFor="period_start">
                                {t('Period start')}
                            </FieldLabel>
                            <DatePicker
                                value={periodStart}
                                onChange={(value) =>
                                    setPeriodStart(value ?? '')
                                }
                                placeholder={t('Pick a start date')}
                                className="w-full"
                            />
                        </Field>

                        <Field>
                            <FieldLabel htmlFor="period_end">
                                {t('Period end')}
                            </FieldLabel>
                            <DatePicker
                                value={periodEnd}
                                onChange={(value) => setPeriodEnd(value ?? '')}
                                placeholder={t('Pick an end date')}
                                className="w-full"
                            />
                        </Field>

                        <Field>
                            <FieldLabel htmlFor="actual_amount">
                                {t('Actual amount received')}
                            </FieldLabel>
                            <Input
                                id="actual_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                value={actualAmount}
                                onChange={(event) =>
                                    setActualAmount(event.target.value)
                                }
                            />
                        </Field>

                        <Field>
                            <FieldLabel htmlFor="notes">
                                Notes (optional)
                            </FieldLabel>
                            <Input
                                id="notes"
                                value={notes}
                                onChange={(event) =>
                                    setNotes(event.target.value)
                                }
                            />
                        </Field>
                    </FieldGroup>

                    <div className="flex items-center gap-4">
                        <span className="text-sm text-muted-foreground">
                            {t('Expected:')}{' '}
                            {!canFetchExpected
                                ? '—'
                                : loadingExpected
                                  ? t('Calculating…')
                                  : expectedAmount !== null
                                    ? money(expectedAmount)
                                    : '—'}
                        </span>

                        <Button
                            type="button"
                            onClick={handleReconcile}
                            disabled={
                                submitting ||
                                !canFetchExpected ||
                                actualAmount === ''
                            }
                        >
                            {t('Record settlement')}
                        </Button>
                    </div>
                </div>

                <DataTableCard>
                    <DataTableCardTable>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Courier')}</TableHead>
                                    <TableHead>{t('Period')}</TableHead>
                                    <TableHead>{t('Expected')}</TableHead>
                                    <TableHead>{t('Actual')}</TableHead>
                                    <TableHead>{t('Difference')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead>{t('Reconciled')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {settlements.length === 0 ? (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell colSpan={8} className="p-0">
                                            <Empty className="border-none py-12">
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Wallet />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {t(
                                                            'No settlements yet',
                                                        )}
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        {t(
                                                            'Reconcile a period above to record your first settlement.',
                                                        )}
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    settlements.map((settlement) => (
                                        <TableRow key={settlement.id}>
                                            <TableCell>
                                                {settlement.delivery_account
                                                    ? courierLabel(
                                                          settlement.delivery_account as DeliveryAccount,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(
                                                    settlement.period_start,
                                                )}{' '}
                                                –{' '}
                                                {formatDate(
                                                    settlement.period_end,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {money(
                                                    settlement.expected_amount,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {money(
                                                    settlement.actual_amount,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {money(
                                                    settlement.difference_amount,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={`border-transparent ${statusClasses[settlement.status]}`}
                                                >
                                                    {settlement.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {settlement.reconciled_at
                                                    ? formatDateTime(
                                                          settlement.reconciled_at,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {settlement.status ===
                                                    'reconciled' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            handleDispute(
                                                                settlement,
                                                            )
                                                        }
                                                    >
                                                        {t('Dispute')}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </DataTableCardTable>
                </DataTableCard>
            </div>
        </>
    );
}

SettlementsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Settlements',
            href: '/settlements',
        },
    ],
};
