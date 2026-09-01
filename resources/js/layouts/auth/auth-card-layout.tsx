import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps, PageProps } from '@/types';

/*
  THESIS: the category standard, played straight — one quiet centered card
  that treats a Moroccan COD merchant's login like a bank treats a vault
  door: calm, exact, nothing decorative between the person and the task.
  OWN-WORLD: app tokens only — violet primary as the single accent, card on
  a near-silent ground with one soft primary bloom; no photo panel, no
  split screen (the old world, discarded).
  STORY: arrive, recognize the brand mark, complete one focused form, move on.
  FIRST VIEWPORT: brand tile top-center, heading + one-line description,
  form card, cross-link. Primary action always the widest, strongest element.
  FORM: canon standing exit, chosen by the user over seeds fd6315a1/29d994eb.
  FINISH: unreviewed and undocumented is unfinished; this build ends with
  the finish review, the verdict, and DESIGN.md.
*/
export default function AuthCardLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage<PageProps>().props;

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-4 py-10 sm:px-6">
            {/* One quiet ground treatment: a soft primary bloom behind the
                card's head, fading before it reaches the fold. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 -top-40 h-96 bg-[radial-gradient(ellipse_50%_100%_at_50%_0%,--alpha(var(--color-primary)/10%),transparent_70%)]"
            />

            <div className="relative w-full max-w-sm motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-500">
                <div className="mb-8 flex flex-col items-center gap-4">
                    <Link
                        href={home()}
                        className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-md shadow-primary/25 transition-transform hover:scale-105"
                        aria-label={name}
                    >
                        <AppLogoIcon className="size-6 fill-current" />
                    </Link>

                    {(title || description) && (
                        <div className="space-y-1.5 text-center">
                            <h1 className="text-xl font-semibold tracking-tight text-balance">
                                {title}
                            </h1>
                            {description && (
                                <p className="mx-auto max-w-xs text-sm text-balance text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                </div>

                <div className="rounded-2xl border bg-card p-6 shadow-xs sm:p-8">
                    {children}
                </div>
            </div>
        </div>
    );
}
