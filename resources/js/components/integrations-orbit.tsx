import { cn } from '@/lib/utils';

/**
 * The integrations EasyFlow sits between, drawn as a hub-and-spoke ring.
 *
 * Eight nodes, not every logo we have: the ring is read at a glance, and
 * past eight the spokes crowd and the icons shrink below recognition.
 * Stores on the upper half, couriers on the lower — orders come in from
 * the top and go out the bottom, which is the product's actual flow.
 */
const NODES = [
    { name: 'Shopify', icon: '/assets/images/shopify_icon.png' },
    { name: 'YouCan', icon: '/assets/images/youcan_icon.png' },
    { name: 'Sendit', icon: '/assets/images/sendit_icon.png' },
    { name: 'OzonExpress', icon: '/assets/images/ozonexpress_icon.png' },
    { name: 'Ameex', icon: '/assets/images/ameex_icon.png' },
    { name: 'Coliix', icon: '/assets/images/coliix_icon.png' },
    { name: 'Lightfunnels', icon: '/assets/images/lightfunnels_icon.png' },
    { name: 'WooCommerce', icon: '/assets/images/woocommerce_icon.png' },
] as const;

/** Distance from hub to node centre, as a fraction of the square's half-width. */
const RADIUS = 0.78;

/** First node sits at the top; the rest follow clockwise. */
const positions = NODES.map((node, index) => {
    const angle = -Math.PI / 2 + (index / NODES.length) * Math.PI * 2;

    return {
        ...node,
        // Percent offsets from the top-left of the square.
        x: 50 + 50 * RADIUS * Math.cos(angle),
        y: 50 + 50 * RADIUS * Math.sin(angle),
    };
});

export function IntegrationsOrbit({ className }: { className?: string }) {
    return (
        <div
            aria-hidden
            className={cn(
                'relative aspect-square w-full select-none',
                className,
            )}
        >
            {/* Soft field behind the hub so the ring reads as one object
                rather than nine loose chips on a flat background. */}
            <div className="absolute inset-[18%] rounded-full bg-primary/[0.06] blur-2xl" />

            {/* Everything that turns lives here. Each node counter-rotates
                at the same speed so the logos stay upright while the ring
                carries them round. */}
            <div className="absolute inset-0 motion-safe:animate-orbit">
                <svg
                    viewBox="0 0 100 100"
                    className="absolute inset-0 size-full overflow-visible"
                >
                    {positions.map((node, index) => (
                        <g key={node.name}>
                            <line
                                x1="50"
                                y1="50"
                                x2={node.x}
                                y2={node.y}
                                className="stroke-primary/30"
                                strokeWidth="0.4"
                                strokeDasharray="1.2 1.6"
                                strokeLinecap="round"
                                vectorEffect="non-scaling-stroke"
                            />
                            {/* The pulse: one dot per spoke, travelling in to
                                the hub. Staggered so they never arrive together. */}
                            <circle
                                r="1.1"
                                className="fill-primary/80 motion-safe:animate-spoke-pulse"
                                style={{
                                    offsetPath: `path("M ${node.x} ${node.y} L 50 50")`,
                                    animationDelay: `${(index * 0.55).toFixed(2)}s`,
                                }}
                            />
                        </g>
                    ))}
                </svg>

                {positions.map((node) => (
                    <div
                        key={node.name}
                        className="absolute flex size-[15%] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border/70 bg-card shadow-md shadow-black/[0.06] motion-safe:animate-orbit-reverse"
                        style={{ left: `${node.x}%`, top: `${node.y}%` }}
                    >
                        <img
                            src={node.icon}
                            alt=""
                            className="size-[52%] object-contain"
                            loading="lazy"
                            decoding="async"
                        />
                    </div>
                ))}
            </div>

            {/* The hub stays still — it is the fixed point the rest moves
                around, and the one thing that should never look busy. */}
            <div className="absolute top-1/2 left-1/2 flex size-[30%] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border/70 bg-card shadow-lg shadow-black/[0.08]">
                <div className="absolute inset-0 rounded-full ring-8 ring-primary/[0.07]" />
                <img
                    src="/assets/images/logo_icon.png"
                    alt=""
                    className="size-[58%] object-contain"
                    decoding="async"
                />
            </div>
        </div>
    );
}
