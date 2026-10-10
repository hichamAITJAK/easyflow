import { router } from '@inertiajs/react';
import { Check, Download, Printer, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    downloadInvoice,
    markInvoicePaid,
    showInvoice,
} from '@/actions/App/Http/Controllers/Commissions/CommissionEntryController';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { formatCalendarDate } from '@/lib/format';
import { statusChipClass } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import type { InvoiceStatus } from '@/types';

type SheetEntry = {
    id: number;
    reference: string | null;
    description: string | null;
    created_at: string | null;
    entry_type: string;
    amount: string;
};

type InvoiceDetail = {
    invoice_number: string;
    status: InvoiceStatus;
    period_start: string | null;
    period_end: string | null;
    issued_at: string | null;
    total_amount: string;
    notes: string | null;
    business_name: string | null;
    agent: { name: string; email: string | null } | null;
    entries: SheetEntry[];
};

/** Paid teal, cancelled red, anything still owed mustard. */
export function invoicePayChip(status: InvoiceStatus): {
    label: string;
    className: string;
} {
    if (status === 'paid') {
        return {
            label: 'Paid',
            className: `${statusChipClass} bg-[#16A08E]/10 text-[#0C7D6F] dark:text-[#4CC7B4]`,
        };
    }

    if (status === 'cancelled') {
        return {
            label: 'Cancelled',
            className: `${statusChipClass} bg-[#D92D20]/10 text-[#B3281D] dark:text-[#F0766C]`,
        };
    }

    return {
        label: 'Unpaid',
        className: `${statusChipClass} bg-[#EFA22C]/12 text-[#A87110] dark:text-[#F3B95C]`,
    };
}

const amount = (value: number): string =>
    value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        useGrouping: false,
    });

/*
 * Print produces the invoice alone. The app root is hidden as a whole (it
 * carries min-h-screen, so hiding only its children would leave a blank
 * first page), and the sheet drops its fixed, scrolling frame.
 */
const PRINT_CSS = `@media print {
  body > #app, [data-slot="sheet-overlay"] { display: none !important; }
  .invoice-sheet { position: static !important; transform: none !important; animation: none !important; max-width: none !important; width: 100% !important; height: auto !important; border: 0 !important; box-shadow: none !important; }
  .invoice-sheet-body { overflow: visible !important; }
  body { background: #fff !important; }
}`;

function Meta({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}

/**
 * One commission invoice: who it pays, every entry it bundles, and the
 * total of exactly those lines. Reads the same JSON as the PDF so the two
 * cannot disagree about what was invoiced.
 */
export function InvoiceSheet({
    invoiceId,
    canMarkPaid,
    onOpenChange,
}: {
    invoiceId: number | null;
    canMarkPaid: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const open = invoiceId !== null;

    // Keyed by invoice id: reopening on another invoice never shows the
    // previous one's lines while the new request is in flight.
    const [state, setState] = useState<{
        id: number | null;
        detail: InvoiceDetail | null;
        failed: boolean;
    }>({ id: null, detail: null, failed: false });
    const [paying, setPaying] = useState(false);

    useEffect(() => {
        if (invoiceId === null) {
            return;
        }

        const controller = new AbortController();

        fetch(showInvoice(invoiceId).url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then((data: InvoiceDetail) =>
                setState({ id: invoiceId, detail: data, failed: false }),
            )
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') {
                    setState({ id: invoiceId, detail: null, failed: true });
                }
            });

        return () => controller.abort();
    }, [invoiceId]);

    const current = state.id === invoiceId ? state : null;
    const detail = current?.detail ?? null;
    const failed = current?.failed ?? false;

    const lines = detail
        ? [...detail.entries].sort((a, b) =>
              (b.created_at ?? '').localeCompare(a.created_at ?? ''),
          )
        : [];
    // The sum of the lines shown, never a separately stored figure.
    const total = lines.reduce((sum, line) => sum + Number(line.amount), 0);
    const dates = lines
        .map((line) => line.created_at)
        .filter((date): date is string => Boolean(date))
        .sort();
    const period =
        dates.length === 0
            ? '—'
            : dates[0] === dates[dates.length - 1]
              ? formatCalendarDate(dates[0])
              : `${formatCalendarDate(dates[0])} – ${formatCalendarDate(dates[dates.length - 1])}`;

    const chip = detail ? invoicePayChip(detail.status) : null;
    const showPay =
        canMarkPaid &&
        detail !== null &&
        detail.status !== 'paid' &&
        detail.status !== 'cancelled';

    const markPaid = () => {
        if (invoiceId === null) {
            return;
        }

        setPaying(true);
        // A partial-free reload: the Entries, By agent and KPI figures all
        // come back from the server with the invoice now paid.
        router.patch(
            markInvoicePaid(invoiceId).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    setState((prev) =>
                        prev.detail
                            ? {
                                  ...prev,
                                  detail: { ...prev.detail, status: 'paid' },
                              }
                            : prev,
                    ),
                onFinish: () => setPaying(false),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                showCloseButton={false}
                className="invoice-sheet gap-0 bg-card p-0 data-[side=right]:w-full data-[side=right]:sm:max-w-[660px]"
            >
                {open && <style>{PRINT_CSS}</style>}

                <div className="flex shrink-0 items-start gap-3 border-b border-border p-5">
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t('Commission invoice')}
                        </p>
                        <SheetTitle className="mt-1 font-mono text-lg font-semibold">
                            {detail?.invoice_number ?? '…'}
                        </SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('Every entry bundled in this invoice.')}
                        </SheetDescription>
                    </div>
                    {chip && (
                        <span className={cn('inline-flex', chip.className)}>
                            {t(chip.label)}
                        </span>
                    )}
                    <SheetClose asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 rounded-md text-muted-foreground hover:bg-accent print:hidden"
                        >
                            <X className="size-4" />
                            <span className="sr-only">{t('Close')}</span>
                        </Button>
                    </SheetClose>
                </div>

                <div className="invoice-sheet-body min-h-0 flex-1 overflow-y-auto p-5">
                    {failed ? (
                        <div className="py-10 text-center">
                            <p className="text-sm font-medium">
                                {t('This invoice could not be loaded')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t(
                                    'Close this and try again — the download still works.',
                                )}
                            </p>
                        </div>
                    ) : !detail ? (
                        <div className="space-y-4">
                            <Skeleton className="h-12 w-full" />
                            <Skeleton className="h-48 w-full" />
                        </div>
                    ) : (
                        <>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
                                <Meta label={t('Agent')}>
                                    <span className="font-medium">
                                        {detail.agent?.name ??
                                            t('Unassigned agent')}
                                    </span>
                                </Meta>
                                <Meta label={t('Issued on')}>
                                    <span className="font-mono tabular-nums">
                                        {detail.issued_at
                                            ? formatCalendarDate(
                                                  detail.issued_at,
                                              )
                                            : '—'}
                                    </span>
                                </Meta>
                                <Meta label={t('Entries')}>
                                    <span className="font-mono tabular-nums">
                                        {lines.length}
                                    </span>
                                </Meta>
                                <Meta label={t('Period')}>
                                    <span className="font-mono text-[13px] tabular-nums">
                                        {period}
                                    </span>
                                </Meta>
                            </dl>

                            <div className="mt-6 overflow-hidden rounded-xl border border-border">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="border-b border-border bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="h-auto px-4 py-2.5 text-xs font-medium text-muted-foreground">
                                                {t('Order')}
                                            </TableHead>
                                            <TableHead className="h-auto px-4 py-2.5 text-xs font-medium text-muted-foreground">
                                                {t('Earned on')}
                                            </TableHead>
                                            <TableHead className="h-auto px-4 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                                {t('Amount')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.length === 0 ? (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={3}
                                                    className="px-4 py-8 text-center text-sm text-muted-foreground"
                                                >
                                                    {t(
                                                        'No line items are attached to this invoice.',
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            lines.map((line) => {
                                                const reversal =
                                                    line.entry_type ===
                                                    'reversal';

                                                return (
                                                    <TableRow
                                                        key={line.id}
                                                        className="border-b border-border last:border-0"
                                                    >
                                                        <TableCell className="px-4 py-2.5 text-sm">
                                                            {line.reference ??
                                                                line.description ??
                                                                '—'}
                                                            {reversal && (
                                                                <span className="text-[#B3281D]">
                                                                    {' · '}
                                                                    {t(
                                                                        'Reversal',
                                                                    )}
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="px-4 py-2.5 text-sm whitespace-nowrap text-muted-foreground">
                                                            {line.created_at
                                                                ? formatCalendarDate(
                                                                      line.created_at,
                                                                  )
                                                                : '—'}
                                                        </TableCell>
                                                        <TableCell className="px-4 py-2.5 text-right text-sm whitespace-nowrap">
                                                            <span
                                                                className={cn(
                                                                    'font-mono tabular-nums',
                                                                    reversal &&
                                                                        'text-[#B3281D]',
                                                                )}
                                                            >
                                                                {amount(
                                                                    Number(
                                                                        line.amount,
                                                                    ),
                                                                )}
                                                            </span>
                                                            <span className="ml-1 text-xs text-muted-foreground">
                                                                MAD
                                                            </span>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                    <TableFooter className="border-t-2 border-border bg-muted/30">
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={2}
                                                className="px-4 py-3 text-sm font-semibold"
                                            >
                                                {t('Total')}
                                            </TableCell>
                                            <TableCell className="px-4 py-3 text-right text-sm whitespace-nowrap">
                                                <span className="font-mono font-semibold text-[#0C7D6F] tabular-nums dark:text-[#4CC7B4]">
                                                    {amount(total)}
                                                </span>
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    MAD
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </div>

                            {detail.notes && (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    {detail.notes}
                                </p>
                            )}
                            <p className="mt-4 text-xs text-muted-foreground">
                                {t(
                                    'Issued by :business. Amounts are gross commission in MAD.',
                                    {
                                        business:
                                            detail.business_name ?? 'EasyFlow',
                                    },
                                )}
                            </p>
                        </>
                    )}
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2 border-t border-border p-4 print:hidden">
                    <Button
                        type="button"
                        variant="outline"
                        className="h-9 gap-2 bg-card px-4 shadow-xs"
                        disabled={!detail}
                        onClick={() => window.print()}
                    >
                        <Printer className="size-4" />
                        {t('Print')}
                    </Button>
                    {invoiceId !== null && (
                        // A real anchor: the response is a file download,
                        // which an Inertia visit could not render.
                        <Button
                            asChild
                            variant="outline"
                            className="h-9 gap-2 bg-card px-4 shadow-xs"
                        >
                            <a href={downloadInvoice(invoiceId).url}>
                                <Download className="size-4" />
                                {t('Download PDF')}
                            </a>
                        </Button>
                    )}
                    {showPay && (
                        <Button
                            type="button"
                            className="ml-auto h-9 gap-2 px-4 font-semibold shadow-xs hover:opacity-90"
                            disabled={paying}
                            onClick={markPaid}
                        >
                            <Check className="size-4" />
                            {t('Mark paid')}
                        </Button>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
