import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    downloadInvoice,
    showInvoice,
} from '@/actions/App/Http/Controllers/Commissions/CommissionEntryController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCalendarDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { InvoiceStatus } from '@/types';

type PreviewEntry = {
    id: number;
    reference: string;
    created_at: string | null;
    entry_type: string;
    amount: string;
};

export type InvoicePreview = {
    invoice_number: string;
    status: InvoiceStatus;
    period_start: string | null;
    period_end: string | null;
    total_amount: string;
    notes: string | null;
    business_name: string | null;
    agent: { name: string; email: string | null } | null;
    entries: PreviewEntry[];
};

const money = (value: string | number): string =>
    `${Number(value).toFixed(2)} MAD`;

function MetaBlock({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <div className="text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-1 text-sm">{children}</div>
        </div>
    );
}

/**
 * Shows what the invoice PDF contains before committing to a download.
 *
 * Deliberately mirrors resources/views/pdf/invoice.blade.php — masthead,
 * three-up meta row, line items, total — so the preview and the file an
 * admin sends to an agent are recognisably the same document. Keep the two
 * in step when either changes.
 */
export function InvoicePreviewDialog({
    invoiceId,
    open,
    onOpenChange,
}: {
    invoiceId: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    /**
     * Keyed by invoice id rather than reset in the effect body: reopening on
     * a different invoice must never show the previous one's figures while
     * the new request is in flight, and a stale-but-matching id is the only
     * thing that makes cached state safe to render.
     */
    const [state, setState] = useState<{
        id: number | null;
        preview: InvoicePreview | null;
        failed: boolean;
    }>({ id: null, preview: null, failed: false });

    useEffect(() => {
        if (!open || invoiceId === null) {
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
            .then((data: InvoicePreview) =>
                setState({ id: invoiceId, preview: data, failed: false }),
            )
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') {
                    setState({ id: invoiceId, preview: null, failed: true });
                }
            });

        return () => controller.abort();
    }, [invoiceId, open]);

    // Anything loaded for a different invoice is treated as not yet loaded.
    const current = state.id === invoiceId ? state : null;
    const preview = current?.preview ?? null;
    const failed = current?.failed ?? false;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader className="sr-only">
                    <DialogTitle>
                        Invoice {preview?.invoice_number ?? ''}
                    </DialogTitle>
                    <DialogDescription>
                        Preview of the invoice before downloading it.
                    </DialogDescription>
                </DialogHeader>

                {failed ? (
                    <div className="py-10 text-center">
                        <p className="text-sm font-medium">
                            This invoice could not be loaded
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Close this and try again — the download still
                            works.
                        </p>
                    </div>
                ) : !preview ? (
                    <div className="space-y-4 py-2">
                        <Skeleton className="h-6 w-40" />
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-32 w-full" />
                    </div>
                ) : (
                    <div className="space-y-6">
                        <div className="flex items-start justify-between gap-4">
                            <span className="font-semibold tracking-tight">
                                {preview.business_name ?? 'EasyFlow'}
                            </span>
                            <div className="text-right">
                                <div className="text-lg font-semibold tracking-tight">
                                    Invoice
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    {preview.invoice_number}
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <MetaBlock label="Billed to">
                                {preview.agent?.name ?? 'Unassigned agent'}
                                {preview.agent?.email && (
                                    <div className="text-muted-foreground">
                                        {preview.agent.email}
                                    </div>
                                )}
                            </MetaBlock>

                            <MetaBlock label="Period">
                                {preview.period_start && preview.period_end
                                    ? `${formatCalendarDate(preview.period_start)} – ${formatCalendarDate(preview.period_end)}`
                                    : '—'}
                            </MetaBlock>

                            <MetaBlock label="Status">
                                <span
                                    className={cn(
                                        'font-semibold tracking-wide uppercase',
                                        preview.status === 'paid'
                                            ? 'text-success'
                                            : 'text-amber-600 dark:text-amber-400',
                                    )}
                                >
                                    {preview.status}
                                </span>
                            </MetaBlock>
                        </div>

                        <div>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="pb-2 text-left text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
                                            Order
                                        </th>
                                        <th className="pb-2 text-left text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
                                            Date
                                        </th>
                                        <th className="pb-2 text-left text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
                                            Type
                                        </th>
                                        <th className="pb-2 text-right text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.entries.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="py-4 text-muted-foreground"
                                            >
                                                No line items are attached to
                                                this invoice.
                                            </td>
                                        </tr>
                                    ) : (
                                        preview.entries.map((entry) => {
                                            const reversal =
                                                entry.entry_type === 'reversal';

                                            return (
                                                <tr
                                                    key={entry.id}
                                                    className="border-b border-border/50"
                                                >
                                                    <td className="py-2">
                                                        {entry.reference}
                                                    </td>
                                                    <td className="py-2 text-muted-foreground">
                                                        {entry.created_at
                                                            ? formatCalendarDate(
                                                                  entry.created_at,
                                                              )
                                                            : '—'}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'py-2',
                                                            reversal &&
                                                                'text-destructive',
                                                        )}
                                                    >
                                                        {reversal
                                                            ? 'Reversal'
                                                            : 'Commission'}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'py-2 text-right tabular-nums',
                                                            reversal &&
                                                                'text-destructive',
                                                        )}
                                                    >
                                                        {Number(
                                                            entry.amount,
                                                        ).toFixed(2)}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>

                            <div className="mt-3 flex items-baseline justify-between border-t-2 border-foreground pt-2">
                                <span className="font-semibold">Total</span>
                                <span className="text-base font-semibold tabular-nums">
                                    {money(preview.total_amount)}
                                </span>
                            </div>
                        </div>

                        {preview.notes && (
                            <p className="border-t pt-3 text-xs text-muted-foreground">
                                {preview.notes}
                            </p>
                        )}
                    </div>
                )}

                <DialogFooter>
                    {invoiceId !== null && (
                        // A real anchor, not a router call — the response is
                        // a file download, so an Inertia visit would get a
                        // PDF body it can't render.
                        <Button asChild variant="outline">
                            <a href={downloadInvoice(invoiceId).url}>
                                <Download />
                                Download PDF
                            </a>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
