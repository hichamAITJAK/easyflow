import { Head, Link } from '@inertiajs/react';
import { CalendarClock, CreditCard } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { blocked as subscriptionBlocked, edit } from '@/routes/subscription';
import type { SubscriptionStatus, SubscriptionSummary } from '@/types';

const STATUS_STYLES: Record<SubscriptionStatus, string> = {
    trialing: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    pending:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    rejected: 'border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400',
    expired: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground',
};

const STATUS_LABELS: Record<SubscriptionStatus, string> = {
    trialing: 'Free trial',
    pending: 'Waiting confirmation',
    active: 'Active',
    rejected: 'Rejected',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const METHOD_LABELS: Record<string, string> = {
    bank_transfer: 'Bank transfer',
    cash: 'Cash',
};

export default function SubscriptionSettings({
    subscription,
    history,
}: {
    subscription: SubscriptionSummary | null;
    history: SubscriptionSummary[];
    trialDays: number;
}) {
    const usedDays =
        subscription?.totalDays != null
            ? subscription.totalDays - subscription.daysRemaining
            : null;
    const progress =
        subscription?.totalDays != null && subscription.totalDays > 0
            ? Math.min(
                  100,
                  Math.max(0, ((usedDays ?? 0) / subscription.totalDays) * 100),
              )
            : 0;

    const showRenew =
        subscription != null &&
        subscription.status !== 'pending' &&
        (subscription.inGracePeriod || subscription.daysRemaining <= 15);

    return (
        <>
            <Head title="Subscription settings" />

            <h1 className="sr-only">Subscription settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Subscription"
                    description="Your current plan and payment history"
                />

                {subscription ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CreditCard className="size-5 text-muted-foreground" />
                                {subscription.planName}
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        'ml-auto text-xs font-medium',
                                        STATUS_STYLES[subscription.status],
                                    )}
                                >
                                    {STATUS_LABELS[subscription.status]}
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                {subscription.inGracePeriod ? (
                                    <>
                                        Ended on {subscription.endsAt} — access
                                        closes on {subscription.graceEndsAt}.
                                        Renew now to keep your access.
                                    </>
                                ) : subscription.endsAt ? (
                                    <>
                                        {subscription.isTrial
                                            ? 'Trial ends'
                                            : 'Renews'}{' '}
                                        on {subscription.endsAt}
                                    </>
                                ) : (
                                    'No end date yet.'
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {subscription.totalDays != null && (
                                <div className="space-y-2">
                                    <div className="flex items-baseline justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            <span
                                                className={cn(
                                                    'font-medium tabular-nums',
                                                    subscription.daysRemaining <=
                                                        7
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-foreground',
                                                )}
                                            >
                                                {subscription.daysRemaining}
                                            </span>{' '}
                                            of {subscription.totalDays} days
                                            remaining
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {subscription.startsAt} →{' '}
                                            {subscription.endsAt}
                                        </span>
                                    </div>
                                    <Progress
                                        value={progress}
                                        aria-label={`${subscription.daysRemaining} of ${subscription.totalDays} days remaining`}
                                    />
                                </div>
                            )}

                            {showRenew && (
                                <Button asChild size="sm">
                                    <Link href={subscriptionBlocked()}>
                                        Renew subscription
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="flex items-center gap-3 text-sm text-muted-foreground">
                            <CalendarClock className="size-5" />
                            No subscription yet.
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Payment requests</CardTitle>
                        <CardDescription>
                            Every trial, payment request, and subscription cycle
                            for this workspace.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {history.length === 0 ? (
                            <p className="py-4 text-sm text-muted-foreground">
                                Nothing here yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead className="text-right">
                                            Period
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="font-medium">
                                                {row.planName}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {row.referenceCode}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'text-xs font-medium',
                                                        STATUS_STYLES[
                                                            row.status
                                                        ],
                                                    )}
                                                >
                                                    {STATUS_LABELS[row.status]}
                                                </Badge>
                                                {row.status === 'rejected' &&
                                                    row.rejectionReason && (
                                                        <div className="mt-1 max-w-52 text-xs text-muted-foreground">
                                                            {
                                                                row.rejectionReason
                                                            }
                                                        </div>
                                                    )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {row.paymentMethod
                                                    ? METHOD_LABELS[
                                                          row.paymentMethod
                                                      ]
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                                {row.startsAt && row.endsAt
                                                    ? `${row.startsAt} → ${row.endsAt}`
                                                    : (row.submittedAt ?? '—')}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

SubscriptionSettings.layout = {
    breadcrumbs: [
        {
            title: 'Subscription settings',
            href: edit(),
        },
    ],
};
