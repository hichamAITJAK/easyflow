/**
 * Goal-driven unit economics for a COD product.
 *
 * The merchant states how many delivered orders they want; everything else
 * is solved backwards from there. Working out how much has to go in to get
 * a target out is the question that actually gets asked before launching a
 * product — "what do I earn per unit" is only answerable afterwards.
 *
 * Two mechanics drive the whole model:
 *
 * 1. Costs land at different funnel stages than revenue does. Ads are paid
 *    on every lead, agent commission on every confirmed order, product and
 *    packaging on every parcel shipped — but money only comes back on the
 *    ones that are actually delivered. A calculation that assumes every
 *    lead converts flatters every product.
 *
 * 2. In cash on delivery the customer pays the courier, the courier keeps
 *    its delivery fee, and the remainder is remitted to the merchant. So
 *    the delivery fee on a delivered parcel is netted out of the payout and
 *    never billed — while a returned parcel collects nothing, so its return
 *    fee is a separate bill with no customer money to net against.
 *
 * Rates mirror the app's own definitions: confirmation_rate is
 * confirmed/leads, delivery_success_rate is delivered/shipped (see
 * IncrementsDailyStats).
 */
export type FunnelInputs = {
    /** Delivered orders the merchant wants to reach. Everything solves back from this. */
    goalDelivered: number;
    confirmationRate: number;
    deliveryRate: number;
    /** What the customer hands the courier at the door. */
    sellingPrice: number;
    /** Paid on every lead, whether or not it ever confirms. */
    adCostPerLead: number;
    /** Agent commission, per order at whichever stage triggers it. */
    confirmationCost: number;
    /**
     * Which stage the agent commission is owed at.
     *
     * Both models are in use: paying on confirmation is the common one, but
     * paying only on delivery is how a business stops rewarding agents for
     * confirming orders that were never going to arrive. The difference is
     * large — at a 50% delivery rate, paying on confirmation costs twice as
     * much as paying on delivery.
     *
     * Mirrors the `trigger_status` choice on a commission rule ("Order
     * confirmed" / "Order delivered"), so the calculator speaks the same
     * language as the agent setup screen.
     */
    commissionTrigger: 'confirmed' | 'delivered';
    /** Courier fee per delivered parcel — deducted from the remittance, not billed. */
    deliveryCost: number;
    /** Billed separately on each parcel that comes back; nothing was collected to net it against. */
    returnCost: number;
    /** Supplier cost of one unit. Charged on everything shipped, since stock leaves the warehouse either way. */
    productCost: number;
    /** Box, label, protective material, per parcel shipped. */
    packagingCost: number;
    /**
     * Share of returned units that cannot be resold — damaged, opened, or
     * lost. Their cost stays sunk; the rest return to stock and are
     * recovered.
     */
    returnSpoilageRate: number;
    /**
     * Fixed charges that are not per-order — rent, subscriptions, network.
     * Taken off the operating profit as a flat sum.
     */
    extraCharges: number;
};

export type FunnelResult = {
    /** Leads that must be generated to reach the goal. */
    leadsRequired: number;
    confirmed: number;
    shipped: number;
    delivered: number;
    returned: number;

    /** Face value collected from customers at the door. */
    grossCollected: number;
    /** What the courier actually transfers, after keeping its delivery fee. */
    courierRemittance: number;
    /** The courier's cut of the delivered parcels. */
    deliveryFeesWithheld: number;

    adSpend: number;
    confirmationSpend: number;
    /** Product cost of everything shipped, before returned stock is recovered. */
    productSpend: number;
    /** Value of returned stock that goes back on the shelf. */
    stockRecovered: number;
    packagingSpend: number;
    returnSpend: number;

    /** Every dirham that has to leave the account to reach the goal. */
    investment: number;

    /** Remittance less investment, before fixed charges. */
    profit: number;
    /** Profit after the fixed charges are taken off. */
    cleanProfit: number;

    /** Profit as a share of the courier remittance. */
    marginPercent: number;
    /** Clean profit as a share of the courier remittance. */
    cleanMarginPercent: number;
    /** Return on the money put in. */
    roiPercent: number;
    /** Return on the money put in, after fixed charges. */
    cleanRoiPercent: number;

    profitPerDelivered: number;
    /** Total spend divided by delivered orders. */
    costPerDelivered: number;

    /**
     * Delivery rate at which profit reaches exactly zero, holding the goal
     * and everything else constant. Null when no rate breaks even, or when
     * there is nothing to solve for.
     */
    breakEvenDeliveryRate: number | null;
};

/** Clamps a percentage-shaped input to 0–100 before it is used as a rate. */
function rate(percent: number): number {
    if (!Number.isFinite(percent)) {
        return 0;
    }

    return Math.min(Math.max(percent, 0), 100) / 100;
}

/** Guards a raw money/count input against NaN and negatives. */
function amount(value: number): number {
    return Number.isFinite(value) ? Math.max(value, 0) : 0;
}

export function calculateFunnel(inputs: FunnelInputs): FunnelResult {
    const goalDelivered = amount(inputs.goalDelivered);
    const confirmationRate = rate(inputs.confirmationRate);
    const deliveryRate = rate(inputs.deliveryRate);

    const sellingPrice = amount(inputs.sellingPrice);
    const deliveryCost = amount(inputs.deliveryCost);
    const productCost = amount(inputs.productCost);
    const spoilage = rate(inputs.returnSpoilageRate);

    // Solved backwards: delivered → shipped → leads. A zero rate makes the
    // goal unreachable rather than infinite, so the funnel collapses to zero
    // instead of producing Infinity and poisoning every figure downstream.
    const reachable = deliveryRate > 0 && confirmationRate > 0;

    const delivered = reachable ? goalDelivered : 0;
    const shipped = reachable ? delivered / deliveryRate : 0;
    const confirmed = shipped;
    const leadsRequired = reachable ? confirmed / confirmationRate : 0;
    const returned = shipped - delivered;

    // The customer pays the courier, the courier keeps its fee and remits
    // the rest — so the delivery fee is withheld from the payout rather
    // than being a bill the merchant settles.
    const grossCollected = delivered * sellingPrice;
    const deliveryFeesWithheld = delivered * deliveryCost;
    const courierRemittance = grossCollected - deliveryFeesWithheld;

    const adSpend = leadsRequired * amount(inputs.adCostPerLead);

    // Paid on confirmation or only on delivery, depending on how the
    // business writes its commission rules.
    const commissionable =
        inputs.commissionTrigger === 'delivered' ? delivered : confirmed;
    const confirmationSpend = commissionable * amount(inputs.confirmationCost);

    const packagingSpend = shipped * amount(inputs.packagingCost);

    // Stock leaves the warehouse for every parcel shipped, so it is all paid
    // for up front. What comes back and can still be sold is recovered.
    const productSpend = shipped * productCost;
    const stockRecovered = returned * (1 - spoilage) * productCost;

    // A returned parcel collected nothing, so there is no remittance to net
    // this against — the courier bills it.
    const returnSpend = returned * amount(inputs.returnCost);

    const investment =
        adSpend +
        confirmationSpend +
        packagingSpend +
        productSpend -
        stockRecovered +
        returnSpend;

    const profit = courierRemittance - investment;
    const cleanProfit = profit - amount(inputs.extraCharges);

    return {
        leadsRequired,
        confirmed,
        shipped,
        delivered,
        returned,

        grossCollected,
        courierRemittance,
        deliveryFeesWithheld,

        adSpend,
        confirmationSpend,
        productSpend,
        stockRecovered,
        packagingSpend,
        returnSpend,

        investment,

        profit,
        cleanProfit,

        marginPercent:
            courierRemittance > 0 ? (profit / courierRemittance) * 100 : 0,
        cleanMarginPercent:
            courierRemittance > 0 ? (cleanProfit / courierRemittance) * 100 : 0,
        roiPercent: investment > 0 ? (profit / investment) * 100 : 0,
        cleanRoiPercent: investment > 0 ? (cleanProfit / investment) * 100 : 0,

        profitPerDelivered: delivered > 0 ? profit / delivered : 0,
        costPerDelivered: delivered > 0 ? investment / delivered : 0,

        breakEvenDeliveryRate: breakEvenDeliveryRate(inputs),
    };
}

/**
 * The delivery rate that makes profit exactly zero, for the same goal.
 *
 * Note this is not simply "profit is linear in the rate": the goal is held
 * fixed, so a worse delivery rate means shipping *more* parcels to still
 * deliver the target — every other stage scales up with it. Writing `d` for
 * the goal, `r` for the delivery rate and `c` for the confirmation rate,
 * shipped is d/r and leads are d/(r·c), so profit takes the form
 *
 *   profit(r) = A − B/r
 *
 * with
 *
 *   A = d·(price − deliveryFee + returnFee − (1−spoilage)·productCost)
 *   B = d·(adCost/c + commission + packaging + returnFee + spoilage·productCost)
 *
 * with the commission term sitting in A instead of B when it is triggered on
 * delivery, since the goal it is owed on is fixed and does not grow as the
 * rate falls.
 *
 * Setting that to zero gives r = B/A directly — no search needed. Verified
 * against a direct evaluation of profit() across randomised inputs.
 */
function breakEvenDeliveryRate(inputs: FunnelInputs): number | null {
    const goalDelivered = amount(inputs.goalDelivered);
    const confirmationRate = rate(inputs.confirmationRate);

    if (goalDelivered <= 0 || confirmationRate <= 0) {
        return null;
    }

    const sellingPrice = amount(inputs.sellingPrice);
    const deliveryCost = amount(inputs.deliveryCost);
    const productCost = amount(inputs.productCost);
    const returnCost = amount(inputs.returnCost);
    const packagingCost = amount(inputs.packagingCost);
    const confirmationCost = amount(inputs.confirmationCost);
    const adCostPerLead = amount(inputs.adCostPerLead);
    const spoilage = rate(inputs.returnSpoilageRate);

    // Commission paid on delivery is owed on the goal itself, which is
    // fixed — so it does not scale with the rate and belongs with the
    // delivered orders rather than with the parcels pushed through.
    const onDelivery = inputs.commissionTrigger === 'delivered';

    // Rate-independent: what each delivered order is worth once the courier
    // takes its cut, plus the return fee and resellable stock it saves by
    // arriving instead of coming back.
    const perDelivered =
        sellingPrice -
        deliveryCost +
        returnCost -
        (1 - spoilage) * productCost -
        (onDelivery ? confirmationCost : 0);

    // Scales with 1/r — a worse rate means pushing more parcels through to
    // still deliver the goal, so ads, packaging, return fees, spoiled stock
    // (and confirmation-triggered commission) all grow as the rate falls.
    const perShipped =
        adCostPerLead / confirmationRate +
        (onDelivery ? 0 : confirmationCost) +
        packagingCost +
        returnCost +
        spoilage * productCost;

    const A = goalDelivered * perDelivered;
    const B = goalDelivered * perShipped;

    if (A <= 0 || B <= 0) {
        return null;
    }

    const requiredRate = (B / A) * 100;

    // Above 100% means the funnel cannot break even however well it
    // delivers — reported as unreachable rather than as a target.
    return requiredRate > 100 ? null : requiredRate;
}
