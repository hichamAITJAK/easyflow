<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\CommissionPaymentMode;
use App\Enums\DeliveryAccountStatus;
use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Business;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\CourierSettlement;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Models\DailyStatsReason;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\EcommercePlatform;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\PerformanceTarget;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Local-only rich demo dataset for visualization/testing: a demo business,
 * its users, stores, delivery accounts, products/variants, customers, and
 * ~350 orders spread over 90 days with realistic status distributions —
 * enough substance for every dashboard/report/chart to render meaningfully.
 * Never call from DatabaseSeeder; run explicitly via
 * `php artisan db:seed --class=FakerSeeder`.
 */
class FakerSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Moroccan cities this COD platform ships to — used for customer_city.
     *
     * @var array<int, string>
     */
    private array $cities = ['Casablanca', 'Rabat', 'Marrakech', 'Fes', 'Tanger', 'Agadir', 'Meknes', 'Oujda', 'Kenitra', 'Tetouan'];

    /** @var array<int, User> */
    private array $agents = [];

    /** @var array<int, User> */
    private array $fulfilmentAgents = [];

    /** @var array<int, Store> */
    private array $stores = [];

    /** @var array<int, DeliveryAccount> */
    private array $deliveryAccounts = [];

    /** @var array<int, Product> */
    private array $products = [];

    /** @var array<int, array<int, ProductVariant>> keyed by product id */
    private array $variantsByProduct = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command->error('FakerSeeder only runs in local environment.');

            return;
        }

        $business = Business::create([
            'name' => 'EasyFlow',
            'slug' => 'no-sheet-cod',
            'status' => BusinessStatus::ACTIVE,
        ]);

        User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Admin',
            'email' => 'admin@email.com',
            'password' => 'password',
            'role' => UserRole::ADMIN,
        ]);

        $agent = User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Confirmation Agent',
            'email' => 'agent@email.com',
            'password' => 'password',
            'role' => UserRole::CONFIRMATION_AGENT,
        ]);

        $fulfilment = User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Fulfilment Agent',
            'email' => 'fulfilment@email.com',
            'password' => 'password',
            'role' => UserRole::FULFILMENT_AGENT,
        ]);

        // Extra agents so agent-performance / commissions reports have real variety.
        $extraAgents = User::factory()->count(3)->confirmationAgent()->create(['business_id' => $business->id]);
        $extraFulfilment = User::factory()->count(1)->fulfilmentAgent()->create(['business_id' => $business->id]);

        $this->agents = [$agent, ...$extraAgents->all()];
        $this->fulfilmentAgents = [$fulfilment, ...$extraFulfilment->all()];

        $this->seedStores($business);
        $this->seedDeliveryAccounts($business);
        $this->seedProducts($business);
        $this->seedCustomers($business);
        $this->seedAgentScopes($business);
        $this->seedOrders($business);
        $this->seedCommissions($business);
        $this->seedCourierSettlements($business);
        $this->seedPerformanceTargets($business);
        $this->seedDailyStatsSummary($business);
        $this->seedDailyStatsReasons($business);
    }

    private function seedStores(Business $business): void
    {
        $platforms = EcommercePlatform::all();
        $names = ['Atlas Beauty Shop', 'MarocDeals', 'Souk Express', 'Casa Gadgets'];

        foreach ($names as $i => $name) {
            $this->stores[] = Store::factory()->create([
                'business_id' => $business->id,
                'platform_id' => $platforms->get($i % $platforms->count())->id,
                'name' => $name,
                'slug' => Str::slug($name),
            ]);
        }
    }

    private function seedDeliveryAccounts(Business $business): void
    {
        $couriers = DeliveryCourrier::all();

        foreach ($couriers as $i => $courier) {
            $this->deliveryAccounts[] = DeliveryAccount::factory()->create([
                'business_id' => $business->id,
                'courier_id' => $courier->id,
                'label' => $courier->name.' account',
                'is_default' => $i === 0,
                'status' => DeliveryAccountStatus::ACTIVE,
            ]);
        }
    }

    private function seedProducts(Business $business): void
    {
        $productNames = [
            'Wireless Earbuds Pro',
            'Smart Watch Series X',
            'Portable Blender',
            'LED Ring Light',
            'Bluetooth Speaker Mini',
            'Electric Nose Trimmer',
            'Yoga Mat Premium',
            'Kitchen Knife Set',
            'Non-Stick Pan Set',
            'USB-C Fast Charger',
            'Phone Camera Lens Kit',
            'Fitness Resistance Bands',
            'Hair Straightener Ceramic',
            'Car Phone Mount',
            'Mini Projector HD',
            'Electric Toothbrush',
            'Skincare Facial Roller',
            'Kids Educational Tablet',
            'Waterproof Backpack',
            'Solar Power Bank',
            'Cordless Hand Vacuum',
            'Aromatherapy Diffuser',
            'Adjustable Dumbbell Set',
            'Baby Monitor Camera',
            'Reusable Water Bottle',
            'Wireless Charging Pad',
            'Smart LED Bulb',
            'Compact Air Fryer',
            'Digital Kitchen Scale',
            'Memory Foam Pillow',
        ];

        foreach ($productNames as $i => $name) {
            $store = $i % 4 === 0 ? null : $this->stores[$i % count($this->stores)];

            $product = Product::factory()->create([
                'business_id' => $business->id,
                'store_id' => $store?->id,
                'external_product_id' => $store ? (string) (10000 + $i) : null,
                'name' => $name,
                'is_test' => $i >= count($productNames) - 2, // last 2 flagged as test products
            ]);

            $this->products[] = $product;

            for ($img = 0; $img < rand(1, 3); $img++) {
                $product->images()->create([
                    'business_id' => $business->id,
                    'path' => "https://picsum.photos/seed/product-{$product->id}-{$img}/600/600",
                    'position' => $img,
                ]);
            }

            // ~60% of products get Color variants.
            if (fake()->boolean(60)) {
                $colorOption = ProductOption::factory()->create([
                    'business_id' => $business->id,
                    'product_id' => $product->id,
                    'name' => 'Color',
                    'position' => 0,
                ]);

                $colors = fake()->randomElements(['Black', 'White', 'Blue', 'Red', 'Green'], rand(2, 3));
                $colorValues = [];
                foreach (array_values($colors) as $pos => $color) {
                    $colorValues[] = ProductOptionValue::factory()->create([
                        'business_id' => $business->id,
                        'product_option_id' => $colorOption->id,
                        'value' => $color,
                        'position' => $pos,
                    ]);
                }

                $variants = [];
                foreach ($colorValues as $pos => $colorValue) {
                    $variant = ProductVariant::factory()->create([
                        'business_id' => $business->id,
                        'product_id' => $product->id,
                        'external_variant_id' => $store ? (string) (100000 + $product->id * 10 + $pos) : null,
                        'sku' => $product->sku.'-'.strtoupper(substr($colorValue->value, 0, 2)),
                        'price' => $product->price,
                        'position' => $pos,
                    ]);

                    $variant->optionValues()->attach($colorValue->id);
                    $variants[] = $variant;

                    $variant->stockMovements()->create([
                        'business_id' => $business->id,
                        'type' => 'restock',
                        'quantity_change' => $variant->inventory_quantity,
                        'note' => 'Initial stock',
                    ]);
                }

                $this->variantsByProduct[$product->id] = $variants;
            }
        }
    }

    private function seedCustomers(Business $business): void
    {
        Customer::factory()->count(120)->create(['business_id' => $business->id]);
        Customer::factory()->count(15)->bestCustomer()->create(['business_id' => $business->id]);
        $blacklisted = Customer::factory()->count(10)->blacklisted()->create(['business_id' => $business->id]);

        foreach ($blacklisted as $customer) {
            CustomerBlacklistEntry::factory()->create([
                'business_id' => $business->id,
                'phone_hash' => $customer->phone_hash,
                'phone_encrypted' => $customer->phone,
                'reason' => fake()->randomElement(['Repeated refusals', 'Fraud suspected', 'Fake orders', 'Abusive behavior']),
                'added_by_user_id' => $this->agents[0]->id,
            ]);
        }
    }

    private function seedAgentScopes(Business $business): void
    {
        // First confirmation agent scoped to a couple of stores; others unscoped (see all).
        AgentScope::factory()->create([
            'business_id' => $business->id,
            'user_id' => $this->agents[0]->id,
            'store_id' => $this->stores[0]->id,
        ]);

        AgentScope::factory()->create([
            'business_id' => $business->id,
            'user_id' => $this->agents[0]->id,
            'store_id' => $this->stores[1]->id,
        ]);
    }

    private function seedOrders(Business $business): void
    {
        $now = Carbon::now();
        $totalOrders = 350;

        // Weighted confirmation-status distribution (roughly reflects a real COD funnel).
        $confirmationWeights = [
            OrderConfirmationStatus::SUBMITTED_TO_COURIER->value => 45,
            OrderConfirmationStatus::CONFIRMED->value => 8,
            OrderConfirmationStatus::CANCELLED->value => 12,
            OrderConfirmationStatus::NEW->value => 6,
            OrderConfirmationStatus::ASSIGNED->value => 6,
            OrderConfirmationStatus::NO_ANSWER->value => 8,
            OrderConfirmationStatus::CALLBACK->value => 4,
            OrderConfirmationStatus::VOICEMAIL->value => 3,
            OrderConfirmationStatus::BUSY->value => 3,
            OrderConfirmationStatus::WHATSAPP_SENT->value => 2,
            OrderConfirmationStatus::FAKE->value => 2,
            OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value => 1,
        ];

        $deliveryWeights = [
            OrderDeliveryStatus::DELIVERED->value => 55,
            OrderDeliveryStatus::IN_TRANSIT->value => 12,
            OrderDeliveryStatus::OUT_FOR_DELIVERY->value => 6,
            OrderDeliveryStatus::AWAITING_PICKUP->value => 5,
            OrderDeliveryStatus::READY_FOR_PICKUP->value => 4,
            OrderDeliveryStatus::REFUSED->value => 6,
            OrderDeliveryStatus::RETURNED_IN_TRANSIT->value => 4,
            OrderDeliveryStatus::RETURN_RECEIVED->value => 4,
            OrderDeliveryStatus::POSTPONED->value => 2,
            OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED->value => 1,
            OrderDeliveryStatus::CANCELLED_AT_COURIER->value => 1,
        ];

        $activeStatuses = [
            OrderDeliveryStatus::AWAITING_PICKUP->value,
            OrderDeliveryStatus::READY_FOR_PICKUP->value,
            OrderDeliveryStatus::IN_TRANSIT->value,
            OrderDeliveryStatus::OUT_FOR_DELIVERY->value,
            OrderDeliveryStatus::POSTPONED->value,
            OrderDeliveryStatus::CHANGED->value,
        ];

        for ($i = 0; $i < $totalOrders; $i++) {
            $daysAgo = rand(0, 89);
            $orderedAt = $now->copy()->subDays($daysAgo)->subHours(rand(0, 23))->subMinutes(rand(0, 59));

            $store = fake()->boolean(80) ? fake()->randomElement($this->stores) : null;
            $agent = fake()->randomElement($this->agents);
            $isTest = fake()->boolean(3);

            $confirmationStatus = $this->weightedRandom($confirmationWeights);
            $phone = '06'.fake()->numerify('########');
            $city = fake()->randomElement($this->cities);

            $order = new Order;
            $order->reference = strtoupper(Str::random(8));
            $order->business_id = $business->id;
            $order->store_id = $store?->id;
            $order->external_order_id = $store ? (string) (500000 + $i) : null;
            $order->source_platform = $store?->platform->slug ?? fake()->randomElement(['whatsapp', 'phone_call', 'manual']);
            $order->assigned_agent_id = $confirmationStatus === OrderConfirmationStatus::NEW->value ? null : $agent->id;
            $order->customer_name = fake()->name();
            $order->customer_phone = $phone;
            $order->customer_phone_hash = hash('sha256', $phone);
            $order->customer_address = fake()->streetAddress();
            $order->customer_city = $city;
            $order->customer_ip_address = fake()->ipv4();
            $order->confirmation_status = OrderConfirmationStatus::from($confirmationStatus);
            $order->is_test = $isTest;
            $order->ordered_at = $orderedAt;
            $order->created_at = $orderedAt;
            $order->updated_at = $orderedAt;
            $order->delivery_cost = fake()->randomFloat(2, 25, 45);

            if ($confirmationStatus === OrderConfirmationStatus::CANCELLED->value) {
                $order->cancellation_reason_code = fake()->randomElement(OrderCancelReason::cases())->value;
            }

            if ($confirmationStatus === OrderConfirmationStatus::SUBMITTED_TO_COURIER->value) {
                $deliveryAccount = fake()->randomElement($this->deliveryAccounts);
                $order->delivery_account_id = $deliveryAccount->id;
                $order->courier_tracking_number = strtoupper(Str::random(12));
                $order->courier_slug = $deliveryAccount->courier->slug;
                $order->shipped_at = $orderedAt->copy()->addHours(rand(2, 30));
                $order->ready_for_pickup_at = $order->shipped_at->copy()->subHours(rand(1, 6));

                $deliveryStatus = $this->weightedRandom($deliveryWeights);
                $order->delivery_status = OrderDeliveryStatus::from($deliveryStatus);
                $order->is_delivery_active = in_array($deliveryStatus, $activeStatuses, true);

                if (in_array($deliveryStatus, [OrderDeliveryStatus::RETURNED_IN_TRANSIT->value, OrderDeliveryStatus::RETURN_RECEIVED->value, OrderDeliveryStatus::REFUSED->value], true)) {
                    $order->return_reason_code = fake()->randomElement(OrderReturnReason::cases())->value;
                }

                if ($deliveryStatus === OrderDeliveryStatus::RETURN_RECEIVED->value) {
                    $order->return_received_at = $order->shipped_at->copy()->addDays(rand(1, 5));
                }

                if ($deliveryStatus === OrderDeliveryStatus::OUT_FOR_DELIVERY->value) {
                    $order->delivery_driver_name = fake()->name();
                    $order->delivery_driver_phone = '06'.fake()->numerify('########');
                }

                // Force some "stranded" in-transit orders 3+ days old for the stranded-orders report.
                if ($deliveryStatus === OrderDeliveryStatus::IN_TRANSIT->value && $daysAgo < 4) {
                    $order->shipped_at = $now->copy()->subDays(rand(4, 8));
                }

                if (in_array($order->delivery_status, [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED], true)) {
                    $order->returned_cost = $order->delivery_cost;
                }
                if ($order->delivery_status === OrderDeliveryStatus::REFUSED) {
                    $order->refused_cost = $order->delivery_cost;
                }
            } elseif ($confirmationStatus === OrderConfirmationStatus::CONFIRMED->value && $daysAgo >= 3 && fake()->boolean(30)) {
                // Some confirmed-but-not-shipped orders older than 3 days => stranded "awaiting shipment".
                $order->ordered_at = $now->copy()->subDays(rand(3, 6));
            }

            $order->total_amount = 1; // placeholder, recalculated after items below
            $order->save();

            // Attach 1-3 order items from real products (respecting store scoping when present).
            $eligibleProducts = $store
                ? array_values(array_filter($this->products, fn (Product $p) => $p->store_id === $store->id))
                : $this->products;

            if (empty($eligibleProducts)) {
                $eligibleProducts = $this->products;
            }

            $itemCount = rand(1, 3);
            $chosenProducts = fake()->randomElements($eligibleProducts, min($itemCount, count($eligibleProducts)));
            $total = 0;
            $lineItems = [];

            foreach ($chosenProducts as $product) {
                $variants = $this->variantsByProduct[$product->id] ?? [];
                $variant = ! empty($variants) ? fake()->randomElement($variants) : null;
                $qty = rand(1, 3);
                $unitPrice = (float) ($variant->price ?? $product->price ?? fake()->randomFloat(2, 50, 400));

                $lineItems[] = [
                    'business_id' => $business->id,
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $variant->sku ?? $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'created_at' => $orderedAt,
                    'updated_at' => $orderedAt,
                ];

                $total += $qty * $unitPrice;

                if ($variant && $order->delivery_status === OrderDeliveryStatus::DELIVERED) {
                    $variant->stockMovements()->create([
                        'business_id' => $business->id,
                        'type' => 'sale',
                        'quantity_change' => -$qty,
                        'note' => 'Order '.$order->reference,
                        'created_at' => $order->shipped_at ?? $orderedAt,
                    ]);
                }
            }

            OrderItem::insert($lineItems);
            $order->total_amount = round($total, 2);
            $order->save();

            $this->buildStatusTrail($order, $agent);
        }
    }

    private function buildStatusTrail(Order $order, User $agent): void
    {
        $events = [];
        $cursor = $order->ordered_at->copy();

        $events[] = [
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderConfirmationStatus::NEW->value,
            'changed_by_user_id' => null,
            'note' => null,
            'created_at' => $cursor,
        ];

        if ($order->confirmation_status !== OrderConfirmationStatus::NEW) {
            $cursor = $cursor->copy()->addMinutes(rand(5, 90));
            $events[] = [
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'from_status' => OrderConfirmationStatus::NEW->value,
                'to_status' => OrderConfirmationStatus::ASSIGNED->value,
                'changed_by_user_id' => $agent->id,
                'note' => null,
                'created_at' => $cursor,
            ];

            $previous = OrderConfirmationStatus::ASSIGNED;

            // Some clients don't pick up on the first try — a couple of
            // no-answer attempts before the outcome makes the trail (and
            // the hourly best-hour buckets rebuilt from it) realistic.
            for ($attempt = rand(0, 2); $attempt > 0; $attempt--) {
                $cursor = $cursor->copy()->addMinutes(rand(30, 240));
                $events[] = [
                    'business_id' => $order->business_id,
                    'order_id' => $order->id,
                    'from_status' => $previous->value,
                    'to_status' => OrderConfirmationStatus::NO_ANSWER->value,
                    'changed_by_user_id' => $agent->id,
                    'note' => null,
                    'created_at' => $cursor,
                ];
                $previous = OrderConfirmationStatus::NO_ANSWER;
            }

            // An order that reached the courier passed through CONFIRMED
            // first (UC-7 → UC-12) — the live pipeline always logs both
            // transitions, so the seeded trail must too or every stats
            // rebuild would undercount confirmations.
            if ($order->confirmation_status === OrderConfirmationStatus::SUBMITTED_TO_COURIER) {
                $cursor = $cursor->copy()->addMinutes(rand(10, 180));
                $events[] = [
                    'business_id' => $order->business_id,
                    'order_id' => $order->id,
                    'from_status' => $previous->value,
                    'to_status' => OrderConfirmationStatus::CONFIRMED->value,
                    'changed_by_user_id' => $agent->id,
                    'note' => null,
                    'created_at' => $cursor,
                ];
                $previous = OrderConfirmationStatus::CONFIRMED;
            }

            $cursor = $cursor->copy()->addMinutes(rand(10, 180));
            $events[] = [
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'from_status' => $previous->value,
                'to_status' => $order->confirmation_status->value,
                'changed_by_user_id' => $agent->id,
                'note' => $order->confirmation_status === OrderConfirmationStatus::CANCELLED
                    ? $order->cancellation_reason_code?->description()
                    : null,
                'created_at' => $cursor,
            ];
        }

        if ($order->delivery_status !== null) {
            $cursor = $order->shipped_at?->copy() ?? $cursor->copy()->addHours(1);

            // Terminal outcomes happen days after pickup, not the moment
            // the parcel ships — this gap is what delivery_seconds_total
            // (per-courier "avg days") rebuilds from.
            if (in_array($order->delivery_status, [
                OrderDeliveryStatus::DELIVERED,
                OrderDeliveryStatus::REFUSED,
                OrderDeliveryStatus::RETURNED_IN_TRANSIT,
                OrderDeliveryStatus::RETURN_RECEIVED,
            ], true)) {
                $cursor = $cursor->copy()->addDays(rand(1, 4))->addMinutes(rand(0, 600));
            }

            $events[] = [
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'from_status' => $order->confirmation_status->value,
                'to_status' => $order->delivery_status->value,
                'changed_by_user_id' => null,
                'note' => null,
                'created_at' => $cursor,
            ];
        }

        OrderStatusEvent::insert($events);
    }

    private function seedCommissions(Business $business): void
    {
        foreach ($this->agents as $agent) {
            CommissionRule::factory()->create([
                'business_id' => $business->id,
                'user_id' => $agent->id,
                'payment_mode' => CommissionPaymentMode::COMMISSION,
            ]);
        }

        foreach ($this->fulfilmentAgents as $fulfilmentAgent) {
            CommissionRule::factory()->salary()->create([
                'business_id' => $business->id,
                'user_id' => $fulfilmentAgent->id,
            ]);
        }

        foreach ($this->agents as $agent) {
            $rule = CommissionRule::where('business_id', $business->id)->where('user_id', $agent->id)->first();

            $orders = Order::where('business_id', $business->id)
                ->where('assigned_agent_id', $agent->id)
                ->whereIn('confirmation_status', [OrderConfirmationStatus::CONFIRMED, OrderConfirmationStatus::SUBMITTED_TO_COURIER])
                ->get();

            if ($orders->isEmpty()) {
                continue;
            }

            // One paid invoice covering an older period, one draft covering the current period.
            $pastPeriodStart = Carbon::now()->subMonths(2)->startOfMonth();
            $pastPeriodEnd = $pastPeriodStart->copy()->endOfMonth();
            $currentPeriodStart = Carbon::now()->startOfMonth();
            $currentPeriodEnd = Carbon::now()->endOfMonth();

            $pastOrders = $orders->filter(fn (Order $o) => $o->ordered_at->between($pastPeriodStart, $pastPeriodEnd));
            $currentOrders = $orders->filter(fn (Order $o) => $o->ordered_at->between($currentPeriodStart, $currentPeriodEnd));

            $invoice = null;
            if ($pastOrders->isNotEmpty()) {
                $invoice = Invoice::factory()->paid()->create([
                    'business_id' => $business->id,
                    'user_id' => $agent->id,
                    'period_start' => $pastPeriodStart->toDateString(),
                    'period_end' => $pastPeriodEnd->toDateString(),
                ]);
            }

            $ledgerRows = [];
            $invoiceTotal = 0;

            foreach ($orders as $order) {
                $amount = (float) ($rule->amount ?? fake()->randomFloat(2, 10, 40));
                $isPastPeriod = $pastOrders->contains('id', $order->id);

                $ledgerRows[] = [
                    'business_id' => $business->id,
                    'user_id' => $agent->id,
                    'order_id' => $order->id,
                    'commission_rule_id' => $rule?->id,
                    'invoice_id' => $isPastPeriod ? $invoice?->id : null,
                    'amount' => $amount,
                    'entry_type' => 'earned',
                    'reversed_entry_id' => null,
                    'created_at' => $order->ordered_at,
                ];

                if ($isPastPeriod) {
                    $invoiceTotal += $amount;
                }
            }

            CommissionLedgerEntry::insert($ledgerRows);

            if ($invoice && $invoiceTotal > 0) {
                $invoice->total_amount = round($invoiceTotal, 2);
                $invoice->save();
            }

            if ($currentOrders->isNotEmpty()) {
                Invoice::factory()->create([
                    'business_id' => $business->id,
                    'user_id' => $agent->id,
                    'period_start' => $currentPeriodStart->toDateString(),
                    'period_end' => $currentPeriodEnd->toDateString(),
                    'total_amount' => round($currentOrders->count() * fake()->randomFloat(2, 10, 40), 2),
                    'status' => 'draft',
                ]);
            }
        }
    }

    private function seedCourierSettlements(Business $business): void
    {
        foreach ($this->deliveryAccounts as $account) {
            $pastStart = Carbon::now()->subMonths(2)->startOfMonth();
            $pastEnd = $pastStart->copy()->endOfMonth();
            $expected = fake()->randomFloat(2, 3000, 15000);
            $diff = fake()->randomFloat(2, -200, 200);

            CourierSettlement::factory()->create([
                'business_id' => $business->id,
                'delivery_account_id' => $account->id,
                'period_start' => $pastStart->toDateString(),
                'period_end' => $pastEnd->toDateString(),
                'expected_amount' => $expected,
                'actual_amount' => round($expected + $diff, 2),
                'difference_amount' => round($diff, 2),
                'status' => 'reconciled',
                'reconciled_at' => $pastEnd->copy()->addDays(3),
                'reconciled_by' => $this->agents[0]->id,
            ]);

            $currentStart = Carbon::now()->startOfMonth();
            $currentEnd = Carbon::now()->endOfMonth();
            $currentExpected = fake()->randomFloat(2, 1000, 8000);

            CourierSettlement::factory()->create([
                'business_id' => $business->id,
                'delivery_account_id' => $account->id,
                'period_start' => $currentStart->toDateString(),
                'period_end' => $currentEnd->toDateString(),
                'expected_amount' => $currentExpected,
                'actual_amount' => null,
                'difference_amount' => null,
                'status' => 'pending',
            ]);

            if (fake()->boolean(50)) {
                $disputedStart = Carbon::now()->subMonth()->startOfMonth();
                $disputedEnd = $disputedStart->copy()->endOfMonth();
                $disputedExpected = fake()->randomFloat(2, 2000, 10000);
                $disputedDiff = fake()->randomFloat(2, -500, -50);

                CourierSettlement::factory()->create([
                    'business_id' => $business->id,
                    'delivery_account_id' => $account->id,
                    'period_start' => $disputedStart->toDateString(),
                    'period_end' => $disputedEnd->toDateString(),
                    'expected_amount' => $disputedExpected,
                    'actual_amount' => round($disputedExpected + $disputedDiff, 2),
                    'difference_amount' => round($disputedDiff, 2),
                    'status' => 'disputed',
                    'notes' => 'Discrepancy under review with courier.',
                ]);
            }
        }
    }

    private function seedPerformanceTargets(Business $business): void
    {
        PerformanceTarget::factory()->confirmationRate(80)->create([
            'business_id' => $business->id,
            'user_id' => null,
        ]);

        PerformanceTarget::factory()->deliverySuccessRate(75)->create([
            'business_id' => $business->id,
            'user_id' => null,
        ]);

        foreach ($this->agents as $agent) {
            PerformanceTarget::factory()->confirmationRate(fake()->randomFloat(2, 70, 90))->create([
                'business_id' => $business->id,
                'user_id' => $agent->id,
            ]);
        }
    }

    /**
     * The seeder builds full status trails (buildStatusTrail) and real
     * ledger entries, so instead of hand-synthesizing summary rows — a
     * second implementation of the stats math that would drift from the
     * live listeners — it runs the same stats:rebuild replay production
     * uses as its nightly correctness safety net. One math, one truth:
     * counts, rates, revenue, commission, duration sums, the courier and
     * product dimensions, and the hourly best-hour buckets all land
     * exactly as the real pipeline would have written them.
     */
    private function seedDailyStatsSummary(Business $business): void
    {
        Artisan::call('stats:rebuild', ['--business' => $business->id]);
    }

    private function seedDailyStatsReasons(Business $business): void
    {
        DailyStatsReason::where('business_id', $business->id)->delete();

        $cancelled = Order::where('business_id', $business->id)
            ->where('confirmation_status', OrderConfirmationStatus::CANCELLED)
            ->whereNotNull('cancellation_reason_code')
            ->get()
            ->groupBy(fn (Order $o) => $o->ordered_at->toDateString().'|'.$o->cancellation_reason_code->value);

        foreach ($cancelled as $key => $orders) {
            [$date, $reasonCode] = explode('|', $key);
            $this->makeDailyStatsReasonRow($business->id, $date, 'cancel', $reasonCode, $orders->count());
        }

        $returned = Order::where('business_id', $business->id)
            ->whereIn('delivery_status', [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED])
            ->whereNotNull('return_reason_code')
            ->get()
            ->groupBy(fn (Order $o) => ($o->return_received_at ?? $o->shipped_at ?? $o->ordered_at)->toDateString().'|'.$o->return_reason_code->value);

        foreach ($returned as $key => $orders) {
            [$date, $reasonCode] = explode('|', $key);
            $this->makeDailyStatsReasonRow($business->id, $date, 'return', $reasonCode, $orders->count());
        }
    }

    private function makeDailyStatsReasonRow(int $businessId, string $date, string $reasonType, string $reasonCode, int $count): void
    {
        DailyStatsReason::create([
            'business_id' => $businessId,
            'store_id' => null,
            'agent_id' => null,
            'stat_date' => $date,
            'reason_type' => $reasonType,
            'reason_code' => $reasonCode,
            'count' => $count,
        ]);
    }

    /** @param array<string, int> $weights */
    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, (int) $total);
        $cumulative = 0;

        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }
}
