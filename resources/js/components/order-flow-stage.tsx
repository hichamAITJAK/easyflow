import { useEffect, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/*
  One order's life, on a loop: it drops in from a store, an agent calls,
  it's confirmed, a courier takes it, it's delivered and the counter ticks.
  Purely decorative (aria-hidden by the caller). With reduced motion the
  loop never starts and the delivered frame is shown.
*/

const ORDERS = [
    { name: 'Yassine B.', meta: '249 MAD · Agadir · COD' },
    { name: 'Salma E.', meta: '379 MAD · Casablanca · COD' },
    { name: 'Omar K.', meta: '189 MAD · Marrakech · COD' },
    { name: 'Imane R.', meta: '520 MAD · Tanger · COD' },
    { name: 'Mehdi A.', meta: '299 MAD · Rabat · COD' },
    { name: 'Khadija L.', meta: '449 MAD · Fès · COD' },
];

const STORES = [
    { key: 'shopify', name: 'Shopify', src: '/assets/images/shopify_icon.png' },
    { key: 'youcan', name: 'YouCan', src: '/assets/images/youcan_icon.png' },
    {
        key: 'woo',
        name: 'WooCommerce',
        src: '/assets/images/woocommerce_icon.png',
    },
    {
        key: 'lf',
        name: 'Lightfunnels',
        src: '/assets/images/lightfunnels_icon.png',
    },
];

const COURIERS = [
    { key: 'ameex', name: 'Ameex', src: '/assets/images/ameex_icon.png' },
    { key: 'sendit', name: 'Sendit', src: '/assets/images/sendit_icon.png' },
    {
        key: 'ozon',
        name: 'Ozon Express',
        src: '/assets/images/ozonexpress_icon.png',
    },
];

type Phase = 'in' | 'calling' | 'confirmed' | 'shipped' | 'delivered' | 'exit';

/** Stepper index each phase has reached; 5 = everything done. */
const STEP: Record<Phase, number> = {
    in: 0,
    calling: 1,
    confirmed: 2,
    shipped: 3,
    delivered: 5,
    exit: 5,
};

const STATUS: Record<Phase, { label: string; className: string }> = {
    in: { label: 'NEW', className: 'bg-[#4A72E8]/15 text-[#4A72E8]' },
    calling: { label: 'CALLING…', className: 'bg-[#E9A23B]/15 text-[#B4731B]' },
    confirmed: {
        label: 'CONFIRMED',
        className: 'bg-[#2FB383]/15 text-[#2FB383]',
    },
    shipped: { label: 'SHIPPED', className: 'bg-[#4A72E8] text-white' },
    delivered: { label: 'DELIVERED', className: 'bg-[#2FB383] text-white' },
    exit: { label: 'DELIVERED', className: 'bg-[#2FB383] text-white' },
};

// How long each phase holds before the next, in ms.
const HOLD: Record<Phase, number> = {
    in: 1400,
    calling: 2500,
    confirmed: 1500,
    shipped: 2300,
    delivered: 2100,
    exit: 500,
};

const NEXT: Record<Phase, Phase> = {
    in: 'calling',
    calling: 'confirmed',
    confirmed: 'shipped',
    shipped: 'delivered',
    delivered: 'exit',
    exit: 'in',
};

const STEP_LABELS = [
    'Order in',
    'Agent call',
    'Confirmed',
    'Shipped',
    'Delivered',
];

const START_NUMBER = 2841;
const START_DELIVERED = 213;

export function OrderFlowStage({ className }: { className?: string }) {
    const { t } = useTranslation();

    // Reduced motion: freeze on the delivered frame and never loop. Read
    // once at mount; SSR has no window and renders the first frame.
    const [reduced] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );
    const [loop, setLoop] = useState(0);
    const [phase, setPhase] = useState<Phase>(reduced ? 'delivered' : 'in');
    const [delivered, setDelivered] = useState(START_DELIVERED);
    const [dots, setDots] = useState(1);
    const [bump, setBump] = useState(false);

    // Phase clock. Each phase holds for its own duration, then advances;
    // 'exit' wraps to the next order.
    useEffect(() => {
        if (reduced) {
            return;
        }

        const timer = window.setTimeout(() => {
            const next = NEXT[phase];

            if (next === 'in') {
                setLoop((current) => current + 1);
            }

            if (next === 'delivered') {
                setDelivered((current) => current + 1);
                setBump(true);
                window.setTimeout(() => setBump(false), 550);
            }

            setPhase(next);
        }, HOLD[phase]);

        return () => window.clearTimeout(timer);
    }, [phase, reduced]);

    // "Agent calling…" dots while the call is on.
    useEffect(() => {
        if (phase !== 'calling') {
            return;
        }

        const tick = window.setInterval(
            () => setDots((current) => (current % 3) + 1),
            420,
        );

        return () => window.clearInterval(tick);
    }, [phase]);

    const order = ORDERS[loop % ORDERS.length];
    const store = STORES[loop % STORES.length];
    const courier = COURIERS[loop % COURIERS.length];
    const number = START_NUMBER + loop + 1;
    const step = STEP[phase];
    const status = STATUS[phase];

    const agentOn = phase !== 'in' && phase !== 'exit';
    const agentRinging = phase === 'calling';
    const courierOn =
        phase === 'shipped' || phase === 'delivered' || phase === 'exit';
    const storeLit = phase !== 'exit';
    const courierLit = courierOn;

    return (
        <div className={cn('relative w-full max-w-[430px]', className)}>
            {/* stores */}
            <Lane chips={STORES} liveKey={storeLit ? store.key : null} />

            <Pipe flowing={phase === 'in'} />

            <div className="grid grid-cols-1 items-center gap-4 sm:grid-cols-[1fr_auto] sm:gap-6">
                {/* order card */}
                <div
                    className={cn(
                        'relative min-h-[148px] rounded-2xl bg-white p-4 pb-4 text-[#141A2E] shadow-[0_22px_50px_rgba(0,0,0,.38)]',
                        phase === 'in' && 'motion-safe:animate-stage-card-in',
                        phase === 'exit' &&
                            'motion-safe:animate-stage-card-out',
                    )}
                >
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <span className="font-mono text-xs font-semibold text-[#5B6072]">
                            {t('ORDER')} #{number}
                        </span>
                        <span
                            className={cn(
                                'rounded-full px-2.5 py-1 font-mono text-[0.68rem] font-semibold tracking-wide whitespace-nowrap transition-colors duration-300',
                                status.className,
                            )}
                        >
                            {t(status.label)}
                        </span>
                    </div>
                    <div className="mb-0.5 font-semibold">{order.name}</div>
                    <div className="font-mono text-xs text-[#5B6072]">
                        {order.meta}
                    </div>

                    <div className="mt-3.5 flex min-h-[30px] items-center gap-2">
                        {agentOn && (
                            <Actor
                                avatarClassName={
                                    phase === 'calling'
                                        ? 'bg-primary'
                                        : 'bg-[#2FB383]'
                                }
                                ringing={agentRinging}
                                avatar={<span>🎧</span>}
                            >
                                {phase === 'calling'
                                    ? t('Agent calling') + '.'.repeat(dots)
                                    : t('Confirmed ✓')}
                            </Actor>
                        )}
                        {courierOn && (
                            <Actor
                                avatarClassName="bg-white"
                                avatar={
                                    <img
                                        src={courier.src}
                                        alt=""
                                        className="size-full rounded-full object-contain p-0.5"
                                    />
                                }
                            >
                                {courier.name}
                            </Actor>
                        )}
                    </div>
                </div>

                {/* stepper */}
                <ol className="flex flex-row flex-wrap justify-center gap-3.5 sm:flex-col sm:gap-0">
                    {STEP_LABELS.map((label, index) => {
                        const on = index === step;
                        const done = index < step;

                        return (
                            <li
                                key={label}
                                className="relative flex items-center gap-2.5 sm:py-[7px] sm:before:absolute sm:before:top-[-10px] sm:before:left-[5px] sm:before:h-[18px] sm:before:w-[1.5px] sm:before:bg-white/15 sm:first:before:hidden"
                            >
                                <i
                                    className={cn(
                                        'relative z-10 size-[11px] flex-none rounded-full bg-white/20 transition-[background-color,box-shadow] duration-300',
                                        on &&
                                            'bg-white shadow-[0_0_0_4px_rgba(255,255,255,.18)]',
                                        done && 'bg-[#2FB383]',
                                    )}
                                />
                                <span
                                    className={cn(
                                        'text-[0.7rem] font-medium whitespace-nowrap text-[#F6ECF2]/50 transition-colors duration-300 sm:text-xs',
                                        on && 'text-white',
                                        done && 'text-[#CBE9DC]',
                                    )}
                                >
                                    {t(label)}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            </div>

            <Pipe flowing={phase === 'shipped'} />

            {/* couriers */}
            <Lane chips={COURIERS} liveKey={courierLit ? courier.key : null} />

            {/* counter */}
            <div className="mx-auto mt-7 flex w-max max-w-full items-center justify-center gap-3 rounded-full border border-white/12 bg-white/7 px-5 py-2.5">
                <span className="size-2 rounded-full bg-[#2FB383] motion-safe:animate-stage-blink" />
                <span className="text-xs text-[#D9BFD0]">
                    {t('Delivered today')}
                </span>
                <span
                    className={cn(
                        'inline-block font-mono text-base font-semibold text-white',
                        bump && 'motion-safe:animate-stage-bump',
                    )}
                >
                    {delivered}
                </span>
            </div>
        </div>
    );
}

function Lane({
    chips,
    liveKey,
}: {
    chips: { key: string; name: string; src: string }[];
    liveKey: string | null;
}) {
    return (
        <div className="flex justify-center gap-3.5">
            {chips.map((chip) => (
                <span
                    key={chip.key}
                    title={chip.name}
                    className={cn(
                        'flex size-[46px] flex-none scale-[.92] items-center justify-center rounded-full bg-white opacity-40 transition-[opacity,transform,box-shadow] duration-400',
                        chip.key === liveKey &&
                            'scale-[1.06] opacity-100 shadow-[0_0_0_5px_rgba(74,114,232,.28),0_10px_26px_rgba(0,0,0,.35)]',
                    )}
                >
                    <img
                        src={chip.src}
                        alt={chip.name}
                        className="size-[60%] object-contain"
                    />
                </span>
            ))}
        </div>
    );
}

function Pipe({ flowing }: { flowing: boolean }) {
    return (
        <div className="relative mx-auto my-1.5 h-11 w-0.5 bg-white/18">
            <span
                className={cn(
                    'absolute top-[-4px] left-1/2 size-[9px] -translate-x-1/2 rounded-full bg-[#4A72E8] opacity-0',
                    flowing && 'motion-safe:animate-stage-drip',
                )}
            />
        </div>
    );
}

function Actor({
    avatar,
    avatarClassName,
    ringing = false,
    children,
}: {
    avatar: React.ReactNode;
    avatarClassName?: string;
    ringing?: boolean;
    children: React.ReactNode;
}) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#F7F3EC] py-1 pr-3 pl-1 text-[0.74rem] font-semibold motion-safe:animate-stage-pop">
            <span
                className={cn(
                    'relative flex size-6 flex-none items-center justify-center rounded-full text-[0.68rem] text-white',
                    avatarClassName,
                    ringing &&
                        'after:absolute after:-inset-1 after:rounded-full after:border-2 after:border-[#E9A23B] motion-safe:after:animate-stage-ring',
                )}
            >
                {avatar}
            </span>
            {children}
        </span>
    );
}
