import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps, PageProps } from '@/types';

/*
  THESIS: a working merchant's front door — the form is the tool, the panel
  is the world it runs: a Moroccan medina at dusk with parcels tracing a
  delivery route. No stock gradient, no abstract shapes: the product's own
  geography, painted for the brand.
  OWN-WORLD: app tokens on the form side; the panel carries the violet dusk
  + terracotta palette of the generated scene, veiled by a primary-tinted
  gradient so both halves read as one brand.
  STORY: recognize the world, complete one focused form, get back to orders.
  FIRST VIEWPORT: logo top-left, heading + form left column at max-w-sm;
  full-bleed scene right with one line of copy anchored at its foot.
  FORM: user-pinned split-with-generated-image (beats seed roll by brief).
  FINISH: unreviewed and undocumented is unfinished; this build ends with
  the finish review, the verdict, and DESIGN.md.
*/
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage<PageProps>().props;

    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="relative flex flex-col px-6 py-8 sm:px-10">
                <Link
                    href={home()}
                    className="flex items-center gap-2.5 self-center font-semibold tracking-tight lg:self-start"
                >
                    <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-sm shadow-primary/25">
                        <AppLogoIcon className="size-4.5 fill-current" />
                    </span>
                    {name}
                </Link>

                <div className="flex flex-1 items-center justify-center py-10">
                    <div className="w-full max-w-sm motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-500">
                        {(title || description) && (
                            <div className="mb-8 space-y-1.5">
                                <h1 className="text-2xl font-semibold tracking-tight text-balance">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </div>
                </div>
            </div>

            <div className="relative hidden overflow-hidden lg:block">
                <img
                    src="/assets/images/cod_login_illustration.png"
                    alt=""
                    aria-hidden
                    className="absolute inset-0 size-full object-cover"
                />
                {/* Primary-tinted veil so the scene and the form side read
                    as one brand, plus a foot gradient that carries the copy. */}
                <div className="absolute inset-0 bg-primary/15 mix-blend-multiply" />
                <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />

                <blockquote className="absolute inset-x-0 bottom-0 p-10 text-white">
                    <p className="max-w-md text-lg font-medium text-balance">
                        &ldquo;Every order, every courier, and every store —
                        in one place.&rdquo;
                    </p>
                    <footer className="mt-3 text-sm text-white/70">
                        {name} — built for cash-on-delivery commerce
                    </footer>
                </blockquote>
            </div>
        </div>
    );
}
