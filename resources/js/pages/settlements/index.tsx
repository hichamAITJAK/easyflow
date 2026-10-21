import { Head, router } from '@inertiajs/react';
import { Banknote, Wallet } from 'lucide-react';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate, formatDateTime } from '@/lib/format';
import { statusChipClass } from '@/lib/order-status';
import { dashboard } from '@/routes';
import type {
    CourierSettlement,
    CourierSettlementStatus,
    DeliveryAccount,
    DeliveryCashSettlement,
    DeliveryGuyCash,
} from '@/types';

const statusClasses: Record<CourierSettlementStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    reconciled: 'bg-[#16A08E]/10 text-[#0C7D6F]',
    disputed: 'bg-[#D92D20]/10 text-[#B3281D]',
};

const STATUS_LABELS: Record<CourierSettlementStatus, string> = {
    pending: 'Pending',
    reconciled: 'Reconciled',
    disputed: 'Disputed',
};

/** Same tolerance as CourierSettlementService::MATCH_TOLERANCE_MAD. */
const MATCH_TOLERANCE_MAD = 1;

/**
 * Difference = received − expected. Negative: the courier paid less
 * than it owes (red). Positive: it paid more (green).
 */
function differenceClass(value: string | number | null): string {
    const diff = Number(value ?? 0);

    if (Math.abs(diff) < MATCH_TOLERANCE_MAD) {
        return 'text-[#0C7D6F]';
    }

    return diff < 0
        ? 'font-semibold text-[#B3281D]'
        : 'font-semibold text-[#0C7D6F]';
}

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

/**
 * A delivery guy hands over the cash of every delivered parcel still
 * with him. The parcels say what is expected; the admin types what was
 * actually received, exactly like a courier period.
 */
function DeliveryGuyCashTab({
    deliveryGuys,
    cashSettlements,
}: {
    deliveryGuys: DeliveryGuyCash[];
    cashSettlements: DeliveryCashSettlement[];
}) {
    const { t } = useTranslation();
    const [guyId, setGuyId] = useState('');
    const [received, setReceived] = useState('');
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const guy = deliveryGuys.find((g) => String(g.id) === guyId) ?? null;

    const handleSettle = () => {
        if (!guy) {
            return;
        }

        setSubmitting(true);

        router.post(
            `/users/${guy.id}/cash-settlements`,
            {
                received_amount: received === '' ? undefined : received,
                note: note || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setGuyId('');
                    setReceived('');
                    setNote('');
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="space-y-4">
            <div className="space-y-4 rounded-lg border p-4">
                <h2 className="text-sm font-medium">
                    {t('Record a hand-over')}
                </h2>

                <FieldGroup className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Field>
                        <FieldLabel>{t('Delivery guy')}</FieldLabel>
                        <Select
                            value={guyId}
                            onValueChange={(value) => {
                                setGuyId(value);
                                setReceived('');
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue
                                    placeholder={t('Choose a delivery guy')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {deliveryGuys.map((g) => (
                                    <SelectItem key={g.id} value={String(g.id)}>
                                        {g.name} · {money(g.owed)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field>
                        <FieldLabel htmlFor="received_amount">
                            {t('Actual amount received')}
                        </FieldLabel>
                        <Input
                            id="received_amount"
                            type="number"
                            step="0.01"
                            min="0"
                            value={received}
                            placeholder={guy ? guy.owed.toFixed(2) : ''}
                            onChange={(event) =>
                                setReceived(event.target.value)
                            }
                        />
                    </Field>

                    <Field>
                        <FieldLabel htmlFor="cash_note">
                            {t('Note (optional)')}
                        </FieldLabel>
                        <Input
                            id="cash_note"
                            value={note}
                            maxLength={255}
                            onChange={(event) => setNote(event.target.value)}
                        />
                    </Field>
                </FieldGroup>

                <div className="flex items-center gap-4">
                    <span className="text-sm text-muted-foreground">
                        {t('Expected:')}{' '}
                        {guy
                            ? `${money(guy.owed)} · ${t(':count parcels', { count: guy.parcels })}${
                                  guy.carried !== 0
                                      ? ` · ${t('carried :amount', { amount: money(guy.carried) })}`
                                      : ''
                              }`
                            : '—'}
                    </span>

                    <Button
                        type="button"
                        onClick={handleSettle}
                        disabled={submitting || !guy || guy.owed === 0}
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
                                <TableHead>{t('Delivery guy')}</TableHead>
                                <TableHead>{t('Date')}</TableHead>
                                <TableHead>{t('Parcels')}</TableHead>
                                <TableHead>{t('Expected')}</TableHead>
                                <TableHead>{t('Actual')}</TableHead>
                                <TableHead>{t('Difference')}</TableHead>
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Still owed')}</TableHead>
                                <TableHead>{t('Settled by')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {cashSettlements.length === 0 ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={9} className="p-0">
                                        <Empty className="border-none py-12">
                                            <EmptyHeader>
                                                <EmptyMedia variant="icon">
                                                    <Banknote />
                                                </EmptyMedia>
                                                <EmptyTitle>
                                                    {t('No hand-overs yet')}
                                                </EmptyTitle>
                                                <EmptyDescription>
                                                    {t(
                                                        'Record a hand-over above when a delivery guy brings in his cash.',
                                                    )}
                                                </EmptyDescription>
                                            </EmptyHeader>
                                        </Empty>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                cashSettlements.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">
                                            {row.delivery_guy?.name ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {formatDateTime(row.created_at)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.parcels_count}
                                        </TableCell>
                                        <TableCell className="font-mono tabular-nums">
                                            {money(row.amount)}
                                        </TableCell>
                                        <TableCell className="font-mono tabular-nums">
                                            {money(
                                                row.received_amount ??
                                                    row.amount,
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={`font-mono tabular-nums ${differenceClass(row.difference_amount)}`}
                                        >
                                            {Number(row.difference_amount) >
                                                0 && '+'}
                                            {money(row.difference_amount)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className={`border-transparent ${statusChipClass} ${statusClasses[row.status]}`}
                                            >
                                                {t(STATUS_LABELS[row.status])}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-mono tabular-nums">
                                            {money(row.balance_after)}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {row.settled_by?.name ?? '—'}
                                            {row.note && (
                                                <span className="block text-xs">
                                                    {row.note}
                                                </span>
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
    );
}

export default function SettlementsIndex({
    settlements,
    deliveryAccounts,
    deliveryGuys,
    cashSettlements,
}: {
    settlements: CourierSettlement[];
    deliveryAccounts: DeliveryAccount[];
    deliveryGuys: DeliveryGuyCash[];
    cashSettlements: DeliveryCashSettlement[];
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
            <Head title={t('Settlements')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Settlements')}
                    description={t(
                        'Compare what a courier or delivery guy owes you against what they actually paid.',
                    )}
                />

                <Tabs defaultValue="couriers" className="space-y-4">
                    <TabsList>
                        <TabsTrigger value="couriers">
                            {t('Couriers')}
                        </TabsTrigger>
                        <TabsTrigger value="guys">
                            {t('Delivery guys')}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="guys">
                        <DeliveryGuyCashTab
                            deliveryGuys={deliveryGuys}
                            cashSettlements={cashSettlements}
                        />
                    </TabsContent>

                    <TabsContent value="couriers" className="space-y-4">
                        <div className="space-y-4 rounded-lg border p-4">
                            <h2 className="text-sm font-medium">
                                {t('Reconcile a period')}
                            </h2>

                            <FieldGroup className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                <Field>
                                    <FieldLabel>
                                        {t('Delivery account')}
                                    </FieldLabel>
                                    <Select
                                        value={deliveryAccountId}
                                        onValueChange={setDeliveryAccountId}
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder={t(
                                                    'Choose an account',
                                                )}
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
                                        onChange={(value) =>
                                            setPeriodEnd(value ?? '')
                                        }
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
                                            <TableHead>
                                                {t('Courier')}
                                            </TableHead>
                                            <TableHead>{t('Period')}</TableHead>
                                            <TableHead>
                                                {t('Expected')}
                                            </TableHead>
                                            <TableHead>{t('Actual')}</TableHead>
                                            <TableHead>
                                                {t('Difference')}
                                            </TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>
                                                {t('Reconciled')}
                                            </TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {settlements.length === 0 ? (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={8}
                                                    className="p-0"
                                                >
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
                                                    <TableCell className="font-medium">
                                                        {settlement.delivery_account
                                                            ? courierLabel(
                                                                  settlement.delivery_account as DeliveryAccount,
                                                              )
                                                            : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {formatDate(
                                                            settlement.period_start,
                                                        )}{' '}
                                                        –{' '}
                                                        {formatDate(
                                                            settlement.period_end,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono tabular-nums">
                                                        {money(
                                                            settlement.expected_amount,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono tabular-nums">
                                                        {money(
                                                            settlement.actual_amount,
                                                        )}
                                                    </TableCell>
                                                    <TableCell
                                                        className={`font-mono tabular-nums ${differenceClass(settlement.difference_amount)}`}
                                                    >
                                                        {Number(
                                                            settlement.difference_amount ??
                                                                0,
                                                        ) > 0 && '+'}
                                                        {money(
                                                            settlement.difference_amount,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="outline"
                                                            className={`border-transparent ${statusChipClass} ${statusClasses[settlement.status]}`}
                                                        >
                                                            {t(
                                                                STATUS_LABELS[
                                                                    settlement
                                                                        .status
                                                                ],
                                                            )}
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
                    </TabsContent>
                </Tabs>
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
