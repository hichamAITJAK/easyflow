import { Link, usePage } from '@inertiajs/react';
import AppWordmark from '@/components/app-wordmark';
import { IntegrationsOrbit } from '@/components/integrations-orbit';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';
import type { AuthLayoutProps, PageProps } from '@/types';

/*
  THESIS: a working merchant's front door — the form is the tool, the panel
  shows what the tool connects: the stores orders come from and the couriers
  they go out to, drawn as one slowly turning ring around the EasyFlow hub.
  OWN-WORLD: app tokens on both halves. The panel is a quiet muted field
  with the product's real integration marks on it — no stock scene, no
  painted world, nothing that needs a veil to match the form side.
  STORY: see what EasyFlow sits between, complete one focused form, get
  back to orders.
  FIRST VIEWPORT: logo top-left, heading + form left column at max-w-sm;
  orbit centred right with one line of copy anchored at its foot.
  MOTION: one authored moment (the ring turns, spokes pulse inward); still
  under prefers-reduced-motion.
*/
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();

    const { name } = usePage<PageProps>().props;

    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="relative flex flex-col px-6 py-8 sm:px-10">
                <Link href={home()} className="self-center lg:self-start">
                    <AppWordmark className="h-7" />
                </Link>

                <div className="flex flex-1 items-center justify-center py-10">
                    <div className="w-full max-w-sm motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-500">
                        {(title || description) && (
                            <div className="mb-8 space-y-1.5">
                                <h1 className="text-2xl font-semibold tracking-tight text-balance">
                                    {t(title)}
                                </h1>
                                {description && (
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {t(description)}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </div>
                </div>
            </div>

            <div className="relative hidden overflow-hidden bg-muted/40 lg:flex lg:flex-col lg:items-center lg:justify-center">
                {/* Fine grid so the panel has a surface rather than a void;
                    masked out toward the edges so it never competes with
                    the ring. */}
                <div
                    aria-hidden
                    className="absolute inset-0 [background-image:linear-gradient(to_right,var(--color-border)_1px,transparent_1px),linear-gradient(to_bottom,var(--color-border)_1px,transparent_1px)] [background-size:48px_48px] opacity-40 [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_72%)]"
                />

                <IntegrationsOrbit className="relative max-w-[32rem] px-10" />

                <blockquote className="absolute inset-x-0 bottom-0 p-10">
                    <p className="max-w-md text-lg font-medium text-balance text-foreground">
                        {t('“Every order, every courier, and every store — in one place.”')}
                    </p>
                    <footer className="mt-3 text-sm text-muted-foreground">
                        {name} — built for cash-on-delivery commerce
                    </footer>
                </blockquote>
            </div>
        </div>
    );
}
