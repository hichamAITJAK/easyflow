import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Check,
    LayoutDashboard,
    PhoneCall,
    PlugZap,
    RefreshCw,
    ShieldCheck,
    Store,
    Truck,
    Users,
    Zap,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import AppWordmark from '@/components/app-wordmark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';
import type { PageProps } from '@/types';

const navLinks = [
    { label: 'Features', href: '#features' },
    { label: 'Integrations', href: '#integrations' },
];

const coreFeatures = [
    {
        icon: PlugZap,
        title: 'Connect every store in minutes',
        description:
            'Link your Shopify and YouCan stores with a guided setup. Orders start flowing into EasyFlow automatically, no developer required.',
        points: [
            'Shopify & YouCan integrations',
            'Automatic order sync',
            'Webhook-based updates',
        ],
    },
    {
        icon: Truck,
        title: 'Route orders to the right courier',
        description:
            'Assign orders to OzonExpress, Sendit, or the courier of your choice based on city coverage, and track every shipment from one place.',
        points: [
            'Multi-courier support',
            'City-based routing',
            'Live status tracking',
        ],
    },
    {
        icon: PhoneCall,
        title: 'Confirm orders before they ship',
        description:
            'Give your confirmation team a focused queue to call customers, verify details, and flag risky orders before they reach the courier.',
        points: [
            'Confirmation workflows',
            'Agent assignment',
            'Order status history',
        ],
    },
    {
        icon: Users,
        title: 'One dashboard for your whole team',
        description:
            'Invite agents and admins, scope access per business, and keep every store and courier account organized under one roof.',
        points: [
            'Role-based access',
            'Multi-business support',
            'Full audit trail',
        ],
    },
];

const bentoFeatures = [
    {
        icon: Store,
        title: 'Multi-Store Sync',
        description:
            'Manage as many Shopify and YouCan stores as you need from a single account.',
    },
    {
        icon: Truck,
        title: 'Courier Integrations',
        description:
            'Connect OzonExpress, Sendit, and other delivery partners without leaving EasyFlow.',
    },
    {
        icon: RefreshCw,
        title: 'Automated Order Routing',
        description:
            'Send confirmed orders to the right courier automatically based on your rules.',
    },
    {
        icon: ShieldCheck,
        title: 'Role-Based Access',
        description:
            'Give agents, admins, and business owners exactly the access they need.',
    },
    {
        icon: BarChart3,
        title: 'Real-Time Order Tracking',
        description:
            'Follow every order from confirmation to delivery without switching tabs.',
    },
    {
        icon: LayoutDashboard,
        title: 'Built for Daily Operations',
        description:
            'A single workspace for the people who confirm, ship, and reconcile your orders.',
    },
];

const useCases = [
    {
        tag: 'Fulfillment',
        title: 'Automated Order Routing',
        description:
            'Stop copying order details between spreadsheets and courier portals. EasyFlow hands off confirmed orders automatically.',
    },
    {
        tag: 'Confirmation',
        title: 'Fewer Failed Deliveries',
        description:
            'Give your confirmation agents everything they need to catch bad addresses and duplicate orders before dispatch.',
    },
    {
        tag: 'Operations',
        title: 'Store & Courier Sync',
        description:
            'Keep your Shopify, YouCan, and courier accounts in sync so every team is working from the same order status.',
    },
    {
        tag: 'Insights',
        title: 'Real-Time Reporting',
        description:
            'See active orders, deliveries, and returns across every store and courier as they happen, not at the end of the day.',
    },
];

const footerColumns = [
    {
        title: 'Product',
        links: [
            { label: 'Features', href: '#features' },
            { label: 'Integrations', href: '#integrations' },
        ],
    },
    {
        title: 'Platform',
        links: [
            { label: 'Store Management', href: '#features' },
            { label: 'Courier Integrations', href: '#integrations' },
            { label: 'Team & Roles', href: '#features' },
        ],
    },
];

function useInView<T extends HTMLElement>(): [RefObject<T | null>, boolean] {
    const ref = useRef<T>(null);
    const [inView, setInView] = useState(false);

    useEffect(() => {
        const node = ref.current;

        if (!node) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setInView(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return [ref, inView];
}

function Reveal({
    children,
    className,
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    const [ref, inView] = useInView<HTMLDivElement>();

    return (
        <div
            ref={ref}
            style={{ transitionDelay: `${delay}ms` }}
            className={cn(
                'transition-all duration-700 ease-out',
                inView
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-8 opacity-0',
                className,
            )}
        >
            {children}
        </div>
    );
}

function SectionBadge({ children }: { children: ReactNode }) {
    return (
        <Badge
            variant="outline"
            className="border-primary/30 bg-primary/10 text-primary"
        >
            {children}
        </Badge>
    );
}

export default function Welcome() {
    const { t } = useTranslation();

    const { auth, name } = usePage<PageProps>().props;
    const isAuthed = Boolean(auth?.user);
    const primaryHref = isAuthed ? dashboard() : login();
    const primaryLabel = isAuthed ? t('Go to Dashboard') : t('Get Started');

    return (
        <div className="dark theme-scope bg-background text-foreground">
            <Head title={t('Welcome')} />

            <header className="sticky top-0 z-50 border-b border-border/60 bg-background/80 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                    <Link
                        href="/"
                        className="flex items-center gap-2 font-medium"
                    >
                        <AppWordmark className="h-7" />
                    </Link>

                    <nav className="hidden items-center gap-8 text-sm text-muted-foreground md:flex">
                        {navLinks.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="transition-colors hover:text-foreground"
                            >
                                {t(link.label)}
                            </a>
                        ))}
                    </nav>

                    <div className="flex items-center gap-3">
                        {!isAuthed && (
                            <Link
                                href={login()}
                                className="hidden text-sm text-muted-foreground transition-colors hover:text-foreground sm:block"
                            >
                                {t('Log in')}
                            </Link>
                        )}
                        <Button asChild size="sm">
                            <Link href={primaryHref}>{primaryLabel}</Link>
                        </Button>
                    </div>
                </div>
            </header>

            <main>
                <section className="relative overflow-hidden px-6 pt-20 pb-24 text-center">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 overflow-hidden"
                    >
                        <div
                            className="absolute inset-0"
                            style={{
                                backgroundImage:
                                    'radial-gradient(ellipse 65% 55% at 50% 15%, color-mix(in oklch, var(--primary) 35%, transparent), transparent 70%)',
                            }}
                        />
                        <div
                            className="absolute inset-0 opacity-20"
                            style={{
                                backgroundImage:
                                    'linear-gradient(to right, var(--border) 1px, transparent 1px), linear-gradient(to bottom, var(--border) 1px, transparent 1px)',
                                backgroundSize: '64px 64px',
                                maskImage:
                                    'radial-gradient(ellipse 60% 55% at 50% 0%, black 40%, transparent 100%)',
                            }}
                        />
                        <div className="absolute top-[-80px] left-1/2 h-[620px] w-[900px] -translate-x-1/2 animate-[pulse_8s_ease-in-out_infinite] rounded-full bg-primary/45 blur-[130px]" />
                        <div className="absolute top-[160px] right-[8%] h-[280px] w-[280px] animate-[pulse_10s_ease-in-out_infinite] rounded-full bg-primary/30 blur-[110px]" />
                        <div className="absolute top-[220px] left-[6%] h-[240px] w-[240px] animate-[pulse_12s_ease-in-out_infinite] rounded-full bg-primary/25 blur-[100px]" />
                    </div>

                    <div className="mx-auto max-w-3xl">
                        <Reveal className="flex justify-center">
                            <SectionBadge>
                                {t('Now connecting Shopify, YouCan & more')}
                            </SectionBadge>
                        </Reveal>

                        <Reveal delay={100}>
                            <h1 className="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                                {t('Your COD Business,')}{' '}
                                <span className="text-primary">
                                    {t('Fully Under Control.')}
                                </span>
                            </h1>
                        </Reveal>

                        <Reveal delay={200}>
                            <p className="mx-auto mt-6 max-w-xl text-lg text-balance text-muted-foreground">
                                {t(
                                    'EasyFlow brings your stores, confirmation team, and delivery couriers into one workspace, so orders move from checkout to doorstep without the spreadsheet chaos.',
                                )}
                            </p>
                        </Reveal>

                        <Reveal
                            delay={300}
                            className="mt-8 flex flex-wrap items-center justify-center gap-3"
                        >
                            <Button asChild size="lg">
                                <Link href={primaryHref}>
                                    {isAuthed ? primaryLabel : t('Get Started')}
                                </Link>
                            </Button>
                        </Reveal>
                    </div>

                    <Reveal
                        delay={400}
                        className="relative mx-auto mt-16 max-w-5xl"
                    >
                        <div className="overflow-hidden rounded-xl border border-border bg-card text-left shadow-2xl">
                            <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-4 py-3">
                                <span className="size-2.5 rounded-full bg-destructive/70" />
                                <span className="size-2.5 rounded-full bg-yellow-500/70" />
                                <span className="size-2.5 rounded-full bg-green-500/70" />
                            </div>

                            <div className="grid gap-4 p-6 sm:grid-cols-[auto_1fr]">
                                <div className="hidden flex-col gap-3 rounded-lg border border-border bg-muted/20 p-3 sm:flex">
                                    {[Store, Truck, Users, BarChart3].map(
                                        (Icon, index) => (
                                            <div
                                                key={index}
                                                className={
                                                    'flex size-9 items-center justify-center rounded-md ' +
                                                    (index === 0
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'text-muted-foreground')
                                                }
                                            >
                                                <Icon className="size-4" />
                                            </div>
                                        ),
                                    )}
                                </div>

                                <div className="flex flex-col gap-4">
                                    <div className="flex items-center justify-between">
                                        <p className="text-sm font-medium">
                                            {t('Welcome back, Leandro')}
                                        </p>
                                        <Badge variant="secondary">
                                            {t('3 stores connected')}
                                        </Badge>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-3">
                                        {[
                                            {
                                                label: t('Active Orders'),
                                                value: '128',
                                            },
                                            {
                                                label: t(
                                                    'Pending Confirmation',
                                                ),
                                                value: '34',
                                            },
                                            {
                                                label: t('Delivered Today'),
                                                value: '56',
                                            },
                                        ].map((stat) => (
                                            <div
                                                key={t(stat.label)}
                                                className="rounded-lg border border-border bg-muted/20 p-3"
                                            >
                                                <p className="text-xs text-muted-foreground">
                                                    {t(stat.label)}
                                                </p>
                                                <p className="mt-1 text-xl font-semibold">
                                                    {stat.value}
                                                </p>
                                            </div>
                                        ))}
                                    </div>

                                    <div className="overflow-hidden rounded-lg border border-border">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-muted/30 text-xs text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">
                                                        {t('Order')}
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        {t('Courier')}
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        {t('Status')}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border">
                                                {[
                                                    {
                                                        order: '#10245',
                                                        courier: 'OzonExpress',
                                                        status: 'Delivered',
                                                        tone: 'bg-green-500/15 text-green-500',
                                                    },
                                                    {
                                                        order: '#10244',
                                                        courier: 'Sendit',
                                                        status: t('In Transit'),
                                                        tone: 'bg-blue-500/15 text-blue-500',
                                                    },
                                                    {
                                                        order: '#10243',
                                                        courier: 'OzonExpress',
                                                        status: t(
                                                            'Awaiting Confirmation',
                                                        ),
                                                        tone: 'bg-yellow-500/15 text-yellow-500',
                                                    },
                                                ].map((row) => (
                                                    <tr key={row.order}>
                                                        <td className="px-3 py-2">
                                                            {row.order}
                                                        </td>
                                                        <td className="px-3 py-2 text-muted-foreground">
                                                            {row.courier}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <span
                                                                className={
                                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                                                                    row.tone
                                                                }
                                                            >
                                                                {row.status}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Reveal>
                </section>

                <section
                    id="features"
                    className="border-t border-border/60 px-6 py-24"
                >
                    <div className="mx-auto max-w-6xl">
                        <Reveal className="flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-end">
                            <div>
                                <SectionBadge>{t('Platform')}</SectionBadge>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight">
                                    {t('Multi-store COD management')}
                                </h2>
                                <p className="mt-3 max-w-xl text-muted-foreground">
                                    {t(
                                        "From the moment an order comes in to the moment it's delivered, EasyFlow keeps every store and courier working together.",
                                    )}
                                </p>
                            </div>
                        </Reveal>

                        <div className="mt-12 grid gap-4 sm:grid-cols-2">
                            {coreFeatures.map((feature, index) => (
                                <Reveal
                                    key={t(feature.title)}
                                    delay={index * 80}
                                    className="rounded-xl border border-border bg-card p-6"
                                >
                                    <div className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <feature.icon className="size-5" />
                                    </div>
                                    <h3 className="mt-4 text-lg font-medium">
                                        {t(feature.title)}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {t(feature.description)}
                                    </p>
                                    <ul className="mt-4 space-y-2">
                                        {feature.points.map((point) => (
                                            <li
                                                key={point}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Check className="size-4 shrink-0 text-primary" />
                                                {point}
                                            </li>
                                        ))}
                                    </ul>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                <section
                    id="integrations"
                    className="border-t border-border/60 px-6 py-24"
                >
                    <div className="mx-auto max-w-6xl text-center">
                        <Reveal>
                            <SectionBadge>{t('Enterprise-Grade')}</SectionBadge>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight">
                                {t('Built for serious COD operations')}
                            </h2>
                            <p className="mx-auto mt-3 max-w-xl text-muted-foreground">
                                {t(
                                    'Everything your confirmation, dispatch, and management teams need, in one place.',
                                )}
                            </p>
                        </Reveal>

                        <div className="mt-12 grid gap-4 text-left sm:grid-cols-2 lg:grid-cols-3">
                            {bentoFeatures.map((feature, index) => (
                                <Reveal
                                    key={t(feature.title)}
                                    delay={index * 80}
                                    className="rounded-xl border border-border bg-card p-6"
                                >
                                    <div className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <feature.icon className="size-5" />
                                    </div>
                                    <h3 className="mt-4 font-medium">
                                        {t(feature.title)}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {t(feature.description)}
                                    </p>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="border-t border-border/60 px-6 py-24">
                    <div className="mx-auto max-w-6xl">
                        <Reveal className="text-center">
                            <h2 className="text-3xl font-semibold tracking-tight">
                                {t('Transform Your Business')}
                            </h2>
                            <p className="mx-auto mt-3 max-w-xl text-muted-foreground">
                                {t(
                                    'EasyFlow replaces the spreadsheets and manual handoffs between your stores, confirmation team, and couriers.',
                                )}
                            </p>
                        </Reveal>

                        <div className="mt-12 grid gap-4 sm:grid-cols-2">
                            {useCases.map((useCase, index) => (
                                <Reveal
                                    key={t(useCase.title)}
                                    delay={index * 80}
                                    className="rounded-xl border border-border bg-card p-6"
                                >
                                    <Badge variant="secondary">
                                        {useCase.tag}
                                    </Badge>
                                    <h3 className="mt-4 text-lg font-medium">
                                        {t(useCase.title)}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {t(useCase.description)}
                                    </p>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="border-t border-border/60 px-6 py-24">
                    <Reveal className="mx-auto max-w-2xl text-center">
                        <Zap className="mx-auto size-8 text-primary" />
                        <blockquote className="mt-6 text-xl text-balance">
                            {t(
                                '“Having every store, courier, and order in one workspace changed how our confirmation team works. We stopped chasing spreadsheets and started shipping faster.”',
                            )}
                        </blockquote>
                        <p className="mt-6 text-sm text-muted-foreground">
                            {t('Early EasyFlow merchant')}
                        </p>
                    </Reveal>
                </section>

                <section className="border-t border-border/60 px-6 py-24 text-center">
                    <Reveal>
                        <h2 className="text-3xl font-semibold tracking-tight text-balance">
                            {t('Bring your COD operations')}{' '}
                            <span className="text-primary">
                                {t('online today.')}
                            </span>
                        </h2>
                        <p className="mx-auto mt-3 max-w-md text-muted-foreground">
                            {t(
                                'Connect your first store and start routing orders to your couriers in minutes.',
                            )}
                        </p>
                        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                            <Button asChild size="lg">
                                <Link href={primaryHref}>
                                    {isAuthed ? primaryLabel : t('Get Started')}
                                </Link>
                            </Button>
                        </div>
                    </Reveal>
                </section>
            </main>

            <footer className="border-t border-border/60 px-6 py-12">
                <div className="mx-auto flex max-w-6xl flex-col gap-10 sm:flex-row sm:justify-between">
                    <div>
                        <Link
                            href="/"
                            className="flex items-center gap-2 font-medium"
                        >
                            <AppWordmark className="h-7" />
                        </Link>
                        <p className="mt-3 max-w-xs text-sm text-muted-foreground">
                            {t(
                                'EasyFlow helps COD businesses manage stores, orders, and couriers from one workspace.',
                            )}
                        </p>
                    </div>

                    <div className="grid grid-cols-2 gap-10">
                        {footerColumns.map((column) => (
                            <div key={t(column.title)}>
                                <p className="text-sm font-medium">
                                    {t(column.title)}
                                </p>
                                <ul className="mt-3 space-y-2">
                                    {column.links.map((link) => (
                                        <li key={t(link.label)}>
                                            <a
                                                href={link.href}
                                                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                                            >
                                                {t(link.label)}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="mx-auto mt-10 max-w-6xl border-t border-border/60 pt-6 text-sm text-muted-foreground">
                    &copy; {new Date().getFullYear()} {name}.{' '}
                    {t('All rights reserved.')}
                </div>
            </footer>
        </div>
    );
}
