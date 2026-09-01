import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarClock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { blocked as subscriptionBlocked } from '@/routes/subscription';
import type { PageProps } from '@/types';

/**
 * The renewal ladder, driven by the shared `subscription` prop:
 * - J-15 → J-0 (paid) / J-3 → J-0 (trial): amber "renew soon"
 * - grace window (paid past ends_at):      red countdown to the cut-off
 * Hidden the rest of the time and while a request awaits confirmation.
 */
export function SubscriptionBanner() {
    const { auth, subscription } = usePage<PageProps>().props;

    if (!subscription || subscription.status === 'pending') {
        return null;
    }

    const isAdmin = auth.user?.role === 'admin';
    const { isTrial, daysRemaining, inGracePeriod, endsAt, graceEndsAt } =
        subscription;

    const expiringSoon = isTrial ? daysRemaining <= 3 : daysRemaining <= 15;

    if (!inGracePeriod && !expiringSoon) {
        return null;
    }

    const message = inGracePeriod
        ? `Your subscription ended on ${endsAt}. Access closes on ${graceEndsAt}.`
        : isTrial
          ? daysRemaining === 0
              ? 'Your free trial ends today.'
              : `Your free trial ends in ${daysRemaining} day${daysRemaining === 1 ? '' : 's'}.`
          : daysRemaining === 0
            ? 'Your subscription ends today.'
            : `Your subscription ends in ${daysRemaining} day${daysRemaining === 1 ? '' : 's'}.`;

    return (
        <div
            role="status"
            className={cn(
                'flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b px-4 py-2 text-center text-sm font-medium',
                inGracePeriod
                    ? 'border-red-500/20 bg-red-500/10 text-red-700 dark:text-red-400'
                    : 'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-400',
            )}
        >
            {inGracePeriod ? (
                <AlertTriangle className="size-4 shrink-0" />
            ) : (
                <CalendarClock className="size-4 shrink-0" />
            )}
            <span>{message}</span>
            {isAdmin ? (
                <Link
                    href={subscriptionBlocked()}
                    className="font-semibold underline underline-offset-2"
                >
                    {isTrial ? 'Choose a plan' : 'Renew now'}
                </Link>
            ) : (
                <span className="font-normal opacity-80">
                    Ask your workspace owner to renew.
                </span>
            )}
        </div>
    );
}
