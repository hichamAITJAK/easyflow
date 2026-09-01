import { Link, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { blocked as subscriptionBlocked } from '@/routes/subscription';
import type { PageProps } from '@/types';

/**
 * Sidebar-footer nudge shown to trialing admins only: how much trial is
 * left, and the one-tap path to the upgrade/payment page. Hidden for
 * agents (they can't pay), for paid subscriptions, and while a payment
 * request is pending. Disappears when the sidebar collapses to icons.
 */
export function TrialUpgradeCard({ trialDays = 14 }: { trialDays?: number }) {
    const { auth, subscription } = usePage<PageProps>().props;

    if (
        !subscription ||
        !subscription.isTrial ||
        subscription.status !== 'trialing' ||
        auth.user?.role !== 'admin'
    ) {
        return null;
    }

    const { daysRemaining } = subscription;
    const progress = Math.min(
        100,
        Math.max(0, ((trialDays - daysRemaining) / trialDays) * 100),
    );

    return (
        <div className="relative mx-2 mb-1 overflow-hidden rounded-xl bg-primary p-3.5 text-primary-foreground shadow-md shadow-primary/25 group-data-[collapsible=icon]:hidden">
            {/* Depth from the brand primary itself: a light bloom top-left, a
                darker settle bottom-right — not a second palette. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage:
                        'radial-gradient(circle at 18% 0%, rgba(255,255,255,0.2), transparent 45%), linear-gradient(135deg, transparent 40%, rgba(0,0,0,0.22) 100%)',
                }}
            />

            <div className="relative">
                <div className="flex items-center gap-1.5 text-sm font-semibold tracking-tight">
                    <Sparkles className="size-4 text-amber-300" />
                    Free trial
                    <span className="ml-auto rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-medium tabular-nums">
                        {daysRemaining === 0
                            ? 'Ends today'
                            : `${daysRemaining}d left`}
                    </span>
                </div>

                <p className="mt-1.5 text-xs leading-relaxed text-primary-foreground/85">
                    Upgrade to keep your orders flowing without interruption.
                </p>

                <div
                    role="progressbar"
                    aria-valuenow={Math.round(progress)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label={`Trial: ${daysRemaining} days remaining`}
                    className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-white/20"
                >
                    <div
                        className="h-full rounded-full bg-gradient-to-r from-amber-300 to-amber-400 transition-[width] duration-500 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>

                <Button
                    asChild
                    size="sm"
                    className="mt-3 w-full bg-white font-semibold text-primary shadow-sm hover:bg-white/90 focus-visible:ring-white/60"
                >
                    <Link href={subscriptionBlocked()}>
                        <Sparkles className="size-3.5" />
                        Upgrade now
                    </Link>
                </Button>
            </div>
        </div>
    );
}
