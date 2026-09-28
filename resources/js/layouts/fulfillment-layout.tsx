import { Link, usePage } from '@inertiajs/react';
import { LogOut, PackageCheck } from 'lucide-react';
import posthog from 'posthog-js';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { useWakeLock } from '@/hooks/use-wake-lock';
import { logout } from '@/routes';
import type { PageProps } from '@/types';

/**
 * The shell for the fulfilment workspace.
 *
 * Deliberately not AppLayout: that shell carries a sidebar and
 * breadcrumbs built around navigating between admin screens. A
 * fulfilment agent has exactly one screen and works one-handed while
 * holding a parcel, so everything here is stripped back to a title bar
 * and the content — no navigation to get lost in, and no chrome
 * competing with the scan button for thumb reach.
 */
export default function FulfillmentLayout({ children }: { children: React.ReactNode }) {
    const { auth } = usePage<PageProps>().props;

    // A packing run is minutes of reading the screen without touching it;
    // letting the phone sleep between parcels would mean unlocking it for
    // every single scan.
    useWakeLock();

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        posthog.identify(String(auth.user.id), {
            email: auth.user.email,
            name: auth.user.name,
            role: auth.user.role,
            business_id: auth.user.business_id,
        });

        return () => {
            posthog.reset();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [auth.user?.id]);

    return (
        <div className="flex min-h-svh flex-col bg-background">
            {/* Sticky so the agent always knows which account is scanning —
                parcels are often staged from a shared warehouse phone. */}
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex w-full max-w-lg items-center gap-3 p-4">
                    <PackageCheck className="size-5 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm leading-none font-semibold">Fulfilment</p>
                        <p className="mt-1 truncate text-xs text-muted-foreground">
                            {auth.user?.name}
                        </p>
                    </div>
                    <Button asChild variant="ghost" size="icon">
                        <Link href={logout()} as="button" onClick={() => posthog.reset()}>
                            <LogOut className="size-4" />
                            <span className="sr-only">Log out</span>
                        </Link>
                    </Button>
                </div>
            </header>

            <main className="flex-1">{children}</main>

            <Toaster position="top-center" richColors closeButton />
        </div>
    );
}
