import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { OrderFlowStage } from '@/components/order-flow-stage';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/*
  THESIS: a working merchant's front door. The form is the tool; the plum
  stage beside it shows what the tool does — one order running itself from
  store to courier, over and over.
  LAYOUT: form column centred (logo, heading, form at max-w-[400px]); stage
  column fills the rest with the gradient and the simulation. Below lg the
  two stack, form first.
  MOTION: the logo turns slowly; the stage loops. Both still under
  prefers-reduced-motion.
*/
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="grid min-h-svh lg:grid-cols-[minmax(440px,46%)_1fr]">
            <section className="flex flex-col items-center justify-center px-6 py-11 text-center sm:px-10 lg:px-[clamp(28px,6vw,96px)] lg:py-12">
                <Link
                    href={home()}
                    className="mb-10 inline-flex items-center justify-center lg:mb-12"
                >
                    <AppLogoIcon className="size-[68px] motion-safe:animate-logo-spin" />
                </Link>

                <div className="w-full max-w-[400px] motion-safe:animate-in motion-safe:duration-500 motion-safe:fade-in motion-safe:slide-in-from-bottom-2">
                    {(title || description) && (
                        <div className="mb-10 space-y-2.5">
                            <h1 className="text-[clamp(1.7rem,2.6vw,2.15rem)] font-bold tracking-[-0.03em] text-balance">
                                {t(title ?? '')}
                            </h1>
                            {description && (
                                <p className="text-balance text-muted-foreground">
                                    {t(description)}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="text-left">{children}</div>
                </div>
            </section>

            <aside
                aria-hidden
                className="relative flex flex-col items-center justify-center overflow-hidden bg-[linear-gradient(160deg,#49183C_0%,#2A0C22_78%)] px-5 py-12 text-[#F6ECF2] lg:px-[clamp(24px,4vw,64px)] lg:py-14"
            >
                <div className="pointer-events-none absolute -top-40 -right-36 size-[560px] rounded-full bg-[radial-gradient(circle,rgba(74,114,232,.22),transparent_65%)]" />

                <div className="relative mb-8 max-w-[460px] text-center lg:mb-10">
                    <h2 className="mb-2.5 text-[clamp(1.45rem,2.2vw,1.9rem)] font-bold tracking-tight text-white">
                        {t('Watch an order run itself.')}
                    </h2>
                    <p className="text-[#D9BFD0]">
                        {t('Store in. Courier out. Zero manual updates.')}
                    </p>
                </div>

                <OrderFlowStage />
            </aside>
        </div>
    );
}
