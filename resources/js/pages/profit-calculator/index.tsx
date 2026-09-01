import { Head } from '@inertiajs/react';
import {
    Banknote,
    ChevronRight,
    DollarSign,
    Megaphone,
    Package,
    Percent,
    Plus,
    Receipt,
    RotateCcw,
    Scale,
    Target,
    Trash2,
    Truck,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { calculateFunnel } from './funnel';

type CustomCharge = {
    key: string;
    label: string;
    amount: string;
};

function toNumber(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

function money(value: number): string {
    return `${value.toFixed(2)} MAD`;
}

/**
 * Order counts are projections. Leads and parcels are rounded *up* — you
 * cannot buy 87.3 leads, and rounding down would quietly under-budget the
 * goal the merchant asked for.
 */
function orders(value: number): string {
    return Math.ceil(value - 1e-9).toLocaleString('en-US');
}

/**
 * One stage of the funnel, with a rail marking where the stage sits.
 *
 * The rail is the colour's job here: leads and confirmed are stages money
 * passes *through* (neutral), delivered is where it lands, returned is where
 * it leaks. Reading down the rails shows the shape of the funnel before any
 * number is read. The count keeps its colour too, so the meaning survives
 * where a 2px rail is too subtle to notice.
 */
function StageRow({
    label,
    count,
    note,
    tone = 'default',
}: {
    label: string;
    count: number;
    note: string;
    tone?: 'default' | 'good' | 'bad';
}) {
    return (
        <div className="flex items-baseline gap-2.5">
            <span
                aria-hidden
                className={cn(
                    'h-3.5 w-0.5 shrink-0 translate-y-0.5 rounded-full',
                    tone === 'good' && 'bg-success',
                    tone === 'bad' && 'bg-destructive',
                    tone === 'default' && 'bg-border',
                )}
            />
            <span className="flex flex-1 items-baseline justify-between gap-3">
                <span className="text-sm text-muted-foreground">{label}</span>
                <span className="flex items-baseline gap-2">
                    <span className="text-xs text-muted-foreground/70">
                        {note}
                    </span>
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            tone === 'good' && 'text-success-text',
                            tone === 'bad' && 'text-destructive-text',
                        )}
                    >
                        {orders(count)}
                    </span>
                </span>
            </span>
        </div>
    );
}

/**
 * A total that can be expanded to show the line items behind it.
 *
 * The totals are what gets read while tuning rates; the per-stage rows are
 * audit detail, checked once when the costs are first entered. Collapsed by
 * default so the result column stays short enough to take in at a glance.
 */
function ExpandableTotal({
    label,
    total,
    totalTone,
    railTone,
    itemCount,
    children,
}: {
    label: string;
    total: string;
    totalTone: string;
    /** Border colour of the rule down the expanded line items. */
    railTone: string;
    itemCount: number;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <CollapsibleTrigger className="group flex min-w-0 items-center gap-1 rounded-sm font-medium transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none">
                    <ChevronRight
                        className="size-3.5 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-90"
                        aria-hidden
                    />
                    {label}
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        {open ? '' : `${itemCount} items`}
                    </span>
                </CollapsibleTrigger>
                <span
                    className={cn(
                        'shrink-0 font-semibold tabular-nums',
                        totalTone,
                    )}
                >
                    {total}
                </span>
            </div>

            <CollapsibleContent
                className={cn(
                    'mt-2 space-y-1.5 border-l-2 pl-3',
                    railTone,
                )}
            >
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}

function MoneyRow({
    label,
    detail,
    amount,
    sign = 'minus',
}: {
    label: string;
    detail?: string;
    amount: number;
    sign?: 'plus' | 'minus' | 'none';
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="min-w-0">
                <span className="text-muted-foreground">{label}</span>
                {detail && (
                    <span className="ml-1 text-xs text-muted-foreground/70">
                        {detail}
                    </span>
                )}
            </span>
            {/* The sign glyph stays: direction is never colour-only, which
                also keeps it readable for anyone who cannot separate the
                green from the red. */}
            <span
                className={cn(
                    'shrink-0 tabular-nums',
                    sign === 'plus' && 'text-success-text',
                )}
            >
                {sign === 'minus' && '− '}
                {sign === 'plus' && '+ '}
                {money(amount)}
            </span>
        </div>
    );
}

export default function ProfitCalculatorIndex() {
    const [goalDelivered, setGoalDelivered] = useState('100');
    const [confirmationRate, setConfirmationRate] = useState('50');
    const [deliveryRate, setDeliveryRate] = useState('50');

    const [sellingPrice, setSellingPrice] = useState('');
    const [productCost, setProductCost] = useState('');
    const [adCostPerLead, setAdCostPerLead] = useState('');
    const [confirmationCost, setConfirmationCost] = useState('');
    const [commissionTrigger, setCommissionTrigger] = useState<
        'confirmed' | 'delivered'
    >('confirmed');
    const [deliveryCost, setDeliveryCost] = useState('');
    const [returnCost, setReturnCost] = useState('');
    const [packagingCost, setPackagingCost] = useState('');
    const [returnSpoilageRate, setReturnSpoilageRate] = useState('0');
    const [customCharges, setCustomCharges] = useState<CustomCharge[]>([]);

    const addCustomCharge = () => {
        setCustomCharges((current) => [
            ...current,
            { key: crypto.randomUUID(), label: '', amount: '' },
        ]);
    };

    const updateCustomCharge = (key: string, patch: Partial<CustomCharge>) => {
        setCustomCharges((current) =>
            current.map((charge) =>
                charge.key === key ? { ...charge, ...patch } : charge,
            ),
        );
    };

    const removeCustomCharge = (key: string) => {
        setCustomCharges((current) =>
            current.filter((charge) => charge.key !== key),
        );
    };

    const extraCharges = useMemo(
        () =>
            customCharges.reduce(
                (sum, charge) => sum + toNumber(charge.amount),
                0,
            ),
        [customCharges],
    );

    const result = useMemo(
        () =>
            calculateFunnel({
                goalDelivered: toNumber(goalDelivered),
                confirmationRate: toNumber(confirmationRate),
                deliveryRate: toNumber(deliveryRate),
                sellingPrice: toNumber(sellingPrice),
                adCostPerLead: toNumber(adCostPerLead),
                confirmationCost: toNumber(confirmationCost),
                commissionTrigger,
                deliveryCost: toNumber(deliveryCost),
                returnCost: toNumber(returnCost),
                productCost: toNumber(productCost),
                packagingCost: toNumber(packagingCost),
                returnSpoilageRate: toNumber(returnSpoilageRate),
                extraCharges,
            }),
        [
            goalDelivered,
            confirmationRate,
            deliveryRate,
            sellingPrice,
            adCostPerLead,
            confirmationCost,
            commissionTrigger,
            deliveryCost,
            returnCost,
            productCost,
            packagingCost,
            returnSpoilageRate,
            extraCharges,
        ],
    );

    const hasInput = result.investment > 0 || result.courierRemittance > 0;
    const unreachable =
        toNumber(goalDelivered) > 0 &&
        (toNumber(confirmationRate) <= 0 || toNumber(deliveryRate) <= 0);

    // Reserved for figures whose sign is the point (profit, margin, ROI).
    // Neutral until there is input, so an untouched calculator does not show
    // a page of red zeroes.
    const tone = (value: number) =>
        !hasInput
            ? 'text-muted-foreground'
            : value > 0
              ? 'text-success-text'
              : 'text-destructive-text';

    // Whether the current delivery rate clears the rate needed to break even.
    // Drives the one amber moment on the page.
    const breakEven = result.breakEvenDeliveryRate;
    const belowBreakEven =
        hasInput && breakEven !== null && toNumber(deliveryRate) < breakEven;

    return (
        <>
            <Head title="Profit calculator" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Profit calculator"
                    description="Set a delivered-orders goal and see what it takes to get there: the money in, the leads needed, and what is left at the end."
                />

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                {/* The goal is the one input that drives every
                                    other number, so it carries the accent —
                                    the only primary-coloured mark in the input
                                    column, which is what makes it findable. */}
                                <CardTitle className="flex items-center gap-2">
                                    {/* dark: lifts off --primary, which is
                                        light-mode-tuned and only reaches
                                        3.41:1 inside its own tint on a dark
                                        card — fine for an icon, weak for the
                                        page's one accent mark. */}
                                    <span className="flex size-7 items-center justify-center rounded-md bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary-foreground">
                                        <Target className="size-4" />
                                    </span>
                                    Goal
                                </CardTitle>
                                <CardDescription>
                                    How many delivered orders you want, and the
                                    rates you expect to hit along the way.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-6 sm:grid-cols-3">
                                    <Field>
                                        <FieldLabel htmlFor="goal_delivered">
                                            Delivered orders
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Target />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="goal_delivered"
                                                type="number"
                                                step="1"
                                                min="0"
                                                autoFocus
                                                value={goalDelivered}
                                                onChange={(event) =>
                                                    setGoalDelivered(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="100"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Your target, delivered and paid.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="confirmation_rate">
                                            Confirmation rate
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Percent />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="confirmation_rate"
                                                type="number"
                                                step="1"
                                                min="0"
                                                max="100"
                                                value={confirmationRate}
                                                onChange={(event) =>
                                                    setConfirmationRate(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="50"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Leads your agents confirm.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="delivery_rate">
                                            Delivery rate
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Percent />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="delivery_rate"
                                                type="number"
                                                step="1"
                                                min="0"
                                                max="100"
                                                value={deliveryRate}
                                                onChange={(event) =>
                                                    setDeliveryRate(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="50"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Shipped parcels actually delivered.
                                        </FieldDescription>
                                    </Field>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <span className="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <DollarSign className="size-4" />
                                    </span>
                                    Price and product
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel htmlFor="selling_price">
                                            Selling price
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <DollarSign />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="selling_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={sellingPrice}
                                                onChange={(event) =>
                                                    setSellingPrice(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            What the customer hands the courier.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="product_cost">
                                            Product cost
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Package />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="product_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={productCost}
                                                onChange={(event) =>
                                                    setProductCost(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Paid on every parcel shipped, not
                                            just the delivered ones.
                                        </FieldDescription>
                                    </Field>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <span className="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <Receipt className="size-4" />
                                    </span>
                                    Costs
                                </CardTitle>
                                <CardDescription>
                                    Each lands at a different stage — that is
                                    what makes the rates above matter.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel htmlFor="ad_cost">
                                            Ad cost per lead
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Megaphone />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="ad_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={adCostPerLead}
                                                onChange={(event) =>
                                                    setAdCostPerLead(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Paid on every lead, confirmed or
                                            not.
                                        </FieldDescription>
                                    </Field>

                                    {/* Amount and the stage it is owed at are
                                        one control: they are a single fact
                                        ("5.00 per confirmed order"), and as
                                        separate fields the dropdown sat next
                                        to the delivery fee and read as though
                                        it modified that instead. The two
                                        stages mirror a commission rule's
                                        trigger status, so this and the agent
                                        setup screen stay in agreement. */}
                                    <Field>
                                        <FieldLabel htmlFor="confirmation_cost">
                                            Agent commission
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Banknote />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="confirmation_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={confirmationCost}
                                                onChange={(event) =>
                                                    setConfirmationCost(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                            <InputGroupAddon align="inline-end">
                                                <Select
                                                    value={commissionTrigger}
                                                    onValueChange={(value) =>
                                                        setCommissionTrigger(
                                                            value as
                                                                | 'confirmed'
                                                                | 'delivered',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        size="sm"
                                                        aria-label="Commission is paid per"
                                                        className="h-6 gap-1 border-0 bg-transparent px-1.5 text-xs text-muted-foreground shadow-none focus-visible:ring-0 dark:bg-transparent"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent align="end">
                                                        <SelectItem value="confirmed">
                                                            per confirmed order
                                                        </SelectItem>
                                                        <SelectItem value="delivered">
                                                            per delivered order
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldDescription>
                                            {commissionTrigger === 'delivered'
                                                ? 'Only orders that arrive earn commission.'
                                                : 'Every confirmed order earns it, delivered or not.'}
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="delivery_cost">
                                            Delivery fee
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Truck />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="delivery_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={deliveryCost}
                                                onChange={(event) =>
                                                    setDeliveryCost(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Kept by the courier out of what it
                                            collects — not billed to you.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="return_cost">
                                            Return fee
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <RotateCcw />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="return_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={returnCost}
                                                onChange={(event) =>
                                                    setReturnCost(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Billed to you on each parcel that
                                            comes back.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="packaging_cost">
                                            Packaging cost
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Package />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="packaging_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={packagingCost}
                                                onChange={(event) =>
                                                    setPackagingCost(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Box and label, per parcel shipped.
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="spoilage_rate">
                                            Unsellable returns
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon aria-hidden="true">
                                                <Percent />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="spoilage_rate"
                                                type="number"
                                                step="1"
                                                min="0"
                                                max="100"
                                                value={returnSpoilageRate}
                                                onChange={(event) =>
                                                    setReturnSpoilageRate(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="0"
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            Share of returns you cannot resell.
                                            The rest go back to stock.
                                        </FieldDescription>
                                    </Field>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <span className="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <Wallet className="size-4" />
                                    </span>
                                    Other charges
                                </CardTitle>
                                <CardDescription>
                                    Fixed costs that are not per order — rent,
                                    subscriptions, internet. Taken off the
                                    profit as a flat sum.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">
                                        {customCharges.length === 0
                                            ? 'None added yet.'
                                            : `${money(extraCharges)} total`}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addCustomCharge}
                                        className="gap-1.5"
                                    >
                                        <Plus className="size-3.5" />
                                        Add charge
                                    </Button>
                                </div>

                                {customCharges.length > 0 && (
                                    <div className="space-y-3">
                                        {customCharges.map((charge) => (
                                            <div
                                                key={charge.key}
                                                className="flex items-center gap-2"
                                            >
                                                <InputGroup className="flex-1">
                                                    <InputGroupInput
                                                        value={charge.label}
                                                        onChange={(event) =>
                                                            updateCustomCharge(
                                                                charge.key,
                                                                {
                                                                    label: event
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="Rent, subscriptions…"
                                                    />
                                                </InputGroup>
                                                <InputGroup className="w-40">
                                                    <InputGroupAddon aria-hidden="true">
                                                        <DollarSign />
                                                    </InputGroupAddon>
                                                    <InputGroupInput
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={charge.amount}
                                                        onChange={(event) =>
                                                            updateCustomCharge(
                                                                charge.key,
                                                                {
                                                                    amount: event
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="0.00"
                                                    />
                                                </InputGroup>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        removeCustomCharge(
                                                            charge.key,
                                                        )
                                                    }
                                                    className="shrink-0 text-muted-foreground hover:text-destructive"
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6 lg:sticky lg:top-4 lg:self-start">
                        {/* The three numbers the merchant came for, before any
                            breakdown: what it costs to try, what it takes to
                            get there, and what is left. */}
                        {/* The one region the accent owns outright. Everything
                            here is what the merchant has to commit to reach
                            the goal, so it reads as a single blue block rather
                            than three tinted details on white. Secondary text
                            is derived from the accent hue, not gray, so it
                            belongs to the surface it sits on. */}
                        <Card className="border-transparent bg-primary text-primary-foreground shadow-surface dark:border-primary/40">
                            <CardContent className="space-y-5 pt-6">
                                {unreachable ? (
                                    <p className="text-sm text-primary-foreground">
                                        A rate of 0% makes this goal
                                        unreachable — no number of leads gets
                                        there.
                                    </p>
                                ) : (
                                    <>
                                        <div>
                                            <div className="text-xs font-semibold tracking-widest text-primary-foreground uppercase">
                                                Investment needed
                                            </div>
                                            <div className="mt-1 text-3xl font-bold tabular-nums">
                                                {money(result.investment)}
                                            </div>
                                            {/* Full-strength rather than a
                                                faded tint: on this accent
                                                neither primary is light enough
                                                to carry a dimmer text tier at
                                                4.5:1, so hierarchy comes from
                                                size and weight instead. */}
                                            <p className="mt-1.5 text-xs text-primary-foreground">
                                                Everything you pay out before
                                                the courier settles.
                                            </p>
                                        </div>

                                        <Separator className="bg-primary-foreground/20" />

                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <div className="text-xs font-semibold tracking-widest text-primary-foreground uppercase">
                                                    Leads needed
                                                </div>
                                                <div className="mt-1 text-xl font-bold tabular-nums">
                                                    {orders(
                                                        result.leadsRequired,
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <div className="text-xs font-semibold tracking-widest text-primary-foreground uppercase">
                                                    Profit margin
                                                </div>
                                                {/* Success/destructive would be
                                                    illegible on this blue, so
                                                    the sign is carried by the
                                                    ± prefix and full opacity
                                                    instead of a hue swap. */}
                                                <div className="mt-1 flex items-baseline gap-1 text-xl font-bold tabular-nums">
                                                    {hasInput ? (
                                                        <>
                                                            {result.profit <
                                                                0 && (
                                                                <span aria-hidden>
                                                                    −
                                                                </span>
                                                            )}
                                                            {`${Math.abs(result.marginPercent).toFixed(1)}%`}
                                                        </>
                                                    ) : (
                                                        <span className="text-primary-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Breakdown
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="space-y-2">
                                    <StageRow
                                        label="Leads"
                                        count={result.leadsRequired}
                                        note="to generate"
                                    />
                                    <StageRow
                                        label="Confirmed"
                                        count={result.confirmed}
                                        note={`${toNumber(confirmationRate)}% of leads`}
                                    />
                                    <StageRow
                                        label="Delivered"
                                        count={result.delivered}
                                        note="your goal"
                                        tone="good"
                                    />
                                    <StageRow
                                        label="Returned"
                                        count={result.returned}
                                        note="shipped, not delivered"
                                        tone="bad"
                                    />
                                </div>

                                <Separator />

                                {/* The courier collects from the customer and
                                    keeps its fee, so the delivery cost never
                                    appears as a payout — it is withheld here. */}
                                <ExpandableTotal
                                    label="Courier sends you"
                                    total={money(result.courierRemittance)}
                                    totalTone="text-success-text"
                                    railTone="border-success/30"
                                    itemCount={2}
                                >
                                    <MoneyRow
                                        label="Collected at the door"
                                        detail={`× ${orders(result.delivered)}`}
                                        amount={result.grossCollected}
                                        sign="none"
                                    />
                                    <MoneyRow
                                        label="Courier keeps"
                                        detail="delivery fees"
                                        amount={result.deliveryFeesWithheld}
                                    />
                                </ExpandableTotal>

                                <ExpandableTotal
                                    label="Total invested"
                                    total={`− ${money(result.investment)}`}
                                    totalTone="text-destructive-text"
                                    railTone="border-destructive/30"
                                    itemCount={result.stockRecovered > 0 ? 6 : 5}
                                >
                                    <MoneyRow
                                        label="Ads"
                                        detail={`× ${orders(result.leadsRequired)} leads`}
                                        amount={result.adSpend}
                                    />
                                    <MoneyRow
                                        label="Agent commission"
                                        detail={
                                            commissionTrigger === 'delivered'
                                                ? `× ${orders(result.delivered)} delivered`
                                                : `× ${orders(result.confirmed)} confirmed`
                                        }
                                        amount={result.confirmationSpend}
                                    />
                                    <MoneyRow
                                        label="Product"
                                        detail={`× ${orders(result.shipped)} shipped`}
                                        amount={result.productSpend}
                                    />
                                    {result.stockRecovered > 0 && (
                                        <MoneyRow
                                            label="Stock recovered"
                                            detail="resellable returns"
                                            amount={result.stockRecovered}
                                            sign="plus"
                                        />
                                    )}
                                    <MoneyRow
                                        label="Packaging"
                                        detail={`× ${orders(result.shipped)}`}
                                        amount={result.packagingSpend}
                                    />
                                    <MoneyRow
                                        label="Return fees"
                                        detail={`× ${orders(result.returned)}`}
                                        amount={result.returnSpend}
                                    />
                                </ExpandableTotal>

                                {/* Where the two totals resolve. A tinted band
                                    rather than another white row, so the
                                    answer separates from the arithmetic above
                                    it — and the tint states profit or loss
                                    before the number is read. */}
                                <div
                                    className={cn(
                                        '-mx-2 rounded-lg px-3 py-2.5 transition-colors',
                                        !hasInput
                                            ? 'bg-muted/50'
                                            : result.profit > 0
                                              ? 'bg-success/10'
                                              : 'bg-destructive/10',
                                    )}
                                >
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="text-sm font-semibold">
                                            Profit
                                        </span>
                                        <span
                                            className={cn(
                                                'text-2xl font-bold tabular-nums',
                                                tone(result.profit),
                                            )}
                                        >
                                            {money(result.profit)}
                                        </span>
                                    </div>
                                </div>

                                {/* Three of these four are the same profit seen
                                    three ways, so they share its colour; cost
                                    per delivered is a magnitude with no good
                                    or bad direction and stays neutral. Tinting
                                    it too would imply a verdict the number
                                    does not carry. */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            ROI
                                        </div>
                                        <div
                                            className={cn(
                                                'text-sm font-semibold tabular-nums',
                                                tone(result.profit),
                                            )}
                                        >
                                            {hasInput
                                                ? `${result.roiPercent.toFixed(1)}%`
                                                : '—'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            Per delivered
                                        </div>
                                        <div
                                            className={cn(
                                                'text-sm font-semibold tabular-nums',
                                                tone(result.profit),
                                            )}
                                        >
                                            {hasInput
                                                ? money(
                                                      result.profitPerDelivered,
                                                  )
                                                : '—'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            Margin
                                        </div>
                                        <div
                                            className={cn(
                                                'text-sm font-semibold tabular-nums',
                                                tone(result.profit),
                                            )}
                                        >
                                            {hasInput
                                                ? `${result.marginPercent.toFixed(1)}%`
                                                : '—'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            Cost per delivered
                                        </div>
                                        <div className="text-sm font-semibold tabular-nums">
                                            {hasInput
                                                ? money(result.costPerDelivered)
                                                : '—'}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Kept separate from the operating profit above: these
                            charges are not caused by this product, so mixing
                            them into the per-order maths would misattribute
                            them. */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span className="flex size-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <Wallet className="size-3.5" />
                                    </span>
                                    Clean profit
                                </CardTitle>
                                <CardDescription>
                                    After fixed charges that are not tied to
                                    this product.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <MoneyRow
                                    label="Profit"
                                    amount={result.profit}
                                    sign="none"
                                />
                                <MoneyRow
                                    label="Other charges"
                                    amount={extraCharges}
                                />

                                {/* Same banded treatment as Profit above: both
                                    are terminal figures, so they get the same
                                    visual role rather than two different ones
                                    for the same kind of answer. */}
                                <div
                                    className={cn(
                                        '-mx-2 flex items-baseline justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors',
                                        !hasInput
                                            ? 'bg-muted/50'
                                            : result.cleanProfit > 0
                                              ? 'bg-success/10'
                                              : 'bg-destructive/10',
                                    )}
                                >
                                    <span className="text-sm font-semibold">
                                        Clean profit
                                    </span>
                                    <span
                                        className={cn(
                                            'text-xl font-bold tabular-nums',
                                            tone(result.cleanProfit),
                                        )}
                                    >
                                        {money(result.cleanProfit)}
                                    </span>
                                </div>

                                {/* Each card carries the rates for its own
                                    profit line: margin and ROI here are
                                    computed after the fixed charges, so they
                                    are not the same figures as the breakdown's
                                    and both belong on screen. */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            Clean margin
                                        </div>
                                        <div
                                            className={cn(
                                                'text-sm font-semibold tabular-nums',
                                                tone(result.cleanProfit),
                                            )}
                                        >
                                            {hasInput
                                                ? `${result.cleanMarginPercent.toFixed(1)}%`
                                                : '—'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-xs text-muted-foreground">
                                            Clean ROI
                                        </div>
                                        <div
                                            className={cn(
                                                'text-sm font-semibold tabular-nums',
                                                tone(result.cleanProfit),
                                            )}
                                        >
                                            {hasInput
                                                ? `${result.cleanRoiPercent.toFixed(1)}%`
                                                : '—'}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Amber lives here and nowhere else on the page. It
                            marks the one actionable warning — the funnel is
                            running under the rate it needs — so the colour
                            keeps enough rarity to actually mean something. */}
                        <Card
                            className={cn(
                                'transition-colors',
                                belowBreakEven && 'border-warning/40 bg-warning/5',
                            )}
                        >
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-md',
                                            belowBreakEven
                                                ? 'bg-warning/20 text-warning-text'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Scale className="size-3.5" />
                                    </span>
                                    Break-even
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {breakEven === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        {hasInput
                                            ? 'No delivery rate makes this goal profitable — the price or the costs have to change.'
                                            : 'Enter a price and your costs to see the delivery rate you need.'}
                                    </p>
                                ) : (
                                    <>
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="text-sm text-muted-foreground">
                                                Delivery rate needed
                                            </span>
                                            <span
                                                className={cn(
                                                    'text-lg font-bold tabular-nums',
                                                    belowBreakEven &&
                                                        'text-warning-text',
                                                )}
                                            >
                                                {breakEven.toFixed(1)}%
                                            </span>
                                        </div>

                                        {/* The rate against the bar it has to
                                            clear. The marker is the point of
                                            the whole card, so it is drawn, not
                                            just described underneath. */}
                                        <div
                                            className="relative mt-3 h-1.5 overflow-hidden rounded-full bg-muted"
                                            role="img"
                                            aria-label={`Your delivery rate is ${toNumber(deliveryRate)} percent; break-even needs ${breakEven.toFixed(1)} percent.`}
                                        >
                                            <div
                                                /* The darker text-grade amber,
                                                   not --warning: the light
                                                   fill value is only 1.84:1
                                                   on this track and would
                                                   read as an empty bar. */
                                                className={cn(
                                                    'h-full rounded-full transition-all',
                                                    belowBreakEven
                                                        ? 'bg-warning-text'
                                                        : 'bg-success',
                                                )}
                                                style={{
                                                    width: `${Math.min(Math.max(toNumber(deliveryRate), 0), 100)}%`,
                                                }}
                                            />
                                            <div
                                                className="absolute inset-y-0 w-0.5 bg-foreground/70"
                                                style={{
                                                    left: `${Math.min(Math.max(breakEven, 0), 100)}%`,
                                                }}
                                            />
                                        </div>

                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {belowBreakEven
                                                ? `You are at ${toNumber(deliveryRate)}% — below break-even.`
                                                : `You are at ${toNumber(deliveryRate)}% — above break-even.`}
                                        </p>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

ProfitCalculatorIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Profit calculator',
            href: '/profit-calculator',
        },
    ],
};
