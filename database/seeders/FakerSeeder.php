<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\CommissionPaymentMode;
use App\Enums\ContentRequestStatus;
use App\Enums\CreativeProductStatus;
use App\Enums\DeliveryAccountStatus;
use App\Enums\EcomPlatform;
use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Enums\OrderSource;
use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Business;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\ContentRequest;
use App\Models\ContentRequestItem;
use App\Models\CourierSettlement;
use App\Models\CreativeProduct;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Models\DailyStatsReason;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCashSettlement;
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
use App\Services\Operations\Orders\OrderCodeGenerator;
use Carbon\CarbonInterface;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Local-only rich demo dataset for visualization/testing: a demo business,
 * its users, stores, delivery accounts, products/variants, customers, and
 * ~400 orders spread over 90 days with realistic status distributions —
 * enough substance for every dashboard/report/chart to render meaningfully.
 *
 * Everything looks Moroccan (see MoroccanData): names, 06/07 phones,
 * cities and neighbourhoods, addresses, products priced in MAD. Orders
 * arrive from every connected platform and from the manual channels
 * (WhatsApp, Instagram, …), go out with the couriers or with our own
 * delivery guys, and only recent orders are still in progress.
 *
 * Two businesses, each with its own team, catalogue, customers, orders,
 * money and creatives (see businesses()):
 *  - EasyFlow: five connected platform stores plus the manual channels;
 *  - Souk Lbaraka: two manual stores only — no online shop, every order
 *    typed in from WhatsApp, Instagram, Facebook, TikTok or a phone call.
 *
 * Never call from DatabaseSeeder; run explicitly via
 * `php artisan db:seed --class=FakerSeeder`.
 */
class FakerSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<int, User> */
    private array $agents = [];

    /** @var array<int, User> */
    private array $fulfilmentAgents = [];

    /** @var array<int, User> keyed by delivery city */
    private array $deliveryGuys = [];

    /** @var array<int, float> delivery guy id => his pay per delivered parcel */
    private array $deliveryGuyRates = [];

    /** @var list<User> */
    private array $creativesEditors = [];

    /** @var array<int, Store> */
    private array $stores = [];

    /** @var array<int, DeliveryAccount> */
    private array $deliveryAccounts = [];

    /** @var array<int, Product> */
    private array $products = [];

    /** @var array<int, array<int, array{variant: ProductVariant, value: string}>> keyed by product id */
    private array $variantsByProduct = [];

    /** @var list<Customer> */
    private array $customers = [];

    /** @var list<Customer> */
    private array $loyalCustomers = [];

    /** @var list<Customer> */
    private array $blacklistedCustomers = [];

    /** @var array<string, mixed> the business being seeded, from businesses() */
    private array $profile = [];

    /** Names already given to a staff member, across every business, so logins never collide. */
    private array $usedNames = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command->error('FakerSeeder only runs in local environment.');

            return;
        }

        // A developer seeding demo data wants the whole platform usable,
        // including the super-admin panel, the platforms and the couriers —
        // all idempotent, so this is harmless when db:seed already ran.
        $this->call([SuperAdminSeeder::class, EcommercePlatformSeeder::class, DeliveryCourrierSeeder::class]);

        $profiles = $this->businesses();

        // Every fixed login name is reserved up front, so a generated
        // colleague in one business can never take another's name/email.
        foreach ($profiles as $profile) {
            foreach ($profile['logins'] as [, $name]) {
                $this->usedNames[] = $name;
            }
        }

        foreach ($profiles as $profile) {
            $this->resetState($profile);
            $this->seedBusiness();
        }
    }

    /** One business, start to finish, from the current profile. */
    private function seedBusiness(): void
    {
        $business = Business::create([
            'name' => $this->profile['name'],
            'slug' => $this->profile['slug'],
            'status' => BusinessStatus::ACTIVE,
        ]);

        $this->seedUsers($business);
        $this->seedStores($business);
        $this->seedDeliveryAccounts($business);
        $this->seedProducts($business);
        $this->seedCustomers($business);
        $this->seedAgentScopes($business);
        $this->seedOrders($business);
        $this->refreshCustomerCounts();
        $this->seedCommissions($business);
        $this->seedDeliveryGuyPay($business);
        $this->seedCreatives($business);
        $this->seedCourierSettlements($business);
        $this->seedPerformanceTargets($business);
        $this->seedDailyStatsSummary($business);
        $this->seedDailyStatsReasons($business);
    }

    /**
     * The demo businesses. Logins are all "password".
     *
     * @return list<array<string, mixed>>
     */
    private function businesses(): array
    {
        return [
            [
                'name' => 'EasyFlow',
                'slug' => 'no-sheet-cod',
                'logins' => [
                    'admin' => ['admin@email.com', 'Karim Benjelloun'],
                    'agent' => ['agent@email.com', 'Salma Idrissi'],
                    'fulfilment' => ['fulfilment@email.com', 'Ayoub Ouazzani'],
                    'delivery' => ['delivery@email.com', 'Abdelilah Chraibi'],
                    'creatives' => ['creatives@email.com', 'Hiba Lamrani'],
                ],
                // city => pay per delivered parcel; the first one gets the fixed login.
                'delivery_cities' => ['Casablanca' => 25, 'Rabat' => 25, 'Marrakech' => 20],
                // null: the five connected platform stores.
                'manual_stores' => null,
                // null: the whole catalogue.
                'catalogue' => null,
                'sku_prefix' => 'EF',
                'customers' => 240,
                'orders' => 400,
                'creatives' => [
                    'hero' => ['name' => 'Montre Connectée Ultra 8', 'links' => ['https://casagadgets.ma/products/montre-connectee-ultra-8'],
                        'description' => 'Montrer les notifications WhatsApp et le suivi sport. Public 20-35 ans.'],
                    'second' => ['name' => "Huile d'Argan Bio 100ml", 'price' => 149, 'links' => ['https://atlasbeauty.ma/products/huile-argan-bio'],
                        'description' => 'Insister sur le 100% bio et l\'origine Souss. Avant / après cheveux.'],
                    'third' => ['name' => 'Friteuse à Air 5L', 'links' => ['https://dardeals.ma/products/friteuse-air-5l'],
                        'description' => 'Recettes marocaines sans huile : msemen, frites, poulet. Format cuisine réelle.',
                        'note' => 'Les recettes du Ramadan marchent bien, on continue.'],
                    'pack' => ['name' => 'Pack Beauté Ghassoul & Argan', 'links' => ['https://atlasbeauty.ma/products/kit-ghassoul-savon-beldi', 'https://atlasbeauty.ma/products/huile-argan-bio'],
                        'description' => 'Routine hammam à la maison, ambiance chaleureuse.',
                        'note' => 'Push du jour : 2 vidéos routine hammam.'],
                    'testing' => ['name' => 'Mini Projecteur HD', 'links' => ['https://casagadgets.ma/products/mini-projecteur-hd'],
                        'description' => 'Produit en test : 3 angles différents pour trouver le bon message.',
                        'directions' => ['Angle "cinéma à la maison" : salon le soir, lumières éteintes', 'Angle "cadeau" : unboxing rapide', '']],
                    'inactive' => ['name' => 'Ceinture Amincissante', 'links' => ['https://bladishop.ma/products/ceinture-amincissante'], 'description' => null],
                ],
            ],
            [
                'name' => 'Souk Lbaraka',
                'slug' => 'souk-lbaraka',
                'logins' => [
                    'admin' => ['admin2@email.com', 'Nadia Berrada'],
                    'agent' => ['agent2@email.com', 'Imane Tahiri'],
                    'fulfilment' => ['fulfilment2@email.com', 'Omar Skalli'],
                    'delivery' => ['delivery2@email.com', 'Rachid Amzil'],
                    'creatives' => ['creatives2@email.com', 'Kawtar Fikri'],
                ],
                'delivery_cities' => ['Casablanca' => 25, 'Fès' => 20, 'Tanger' => 22],
                // A brand with no online shop: two manual stores only.
                'manual_stores' => ['Lbaraka Style', 'Lbaraka Maison'],
                'catalogue' => [
                    'Caftan Brodé Femme', 'Djellaba Homme Lin', 'Babouches Cuir de Fès', 'Sac à Main Cuir',
                    "Huile d'Argan Bio 100ml", 'Sérum Vitamine C', 'Kit Ghassoul & Savon Beldi', 'Parfum Musc Tahara',
                    'Service à Thé Marocain 6 verres', 'Tapis de Prière Mousse', 'Bouilloire Électrique Inox',
                    'Set Couteaux Cuisine 6 pièces', 'Coussin Orthopédique', 'Produit Test - Échantillon',
                ],
                'sku_prefix' => 'SL',
                'customers' => 160,
                'orders' => 260,
                'creatives' => [
                    'hero' => ['name' => 'Caftan Brodé Femme', 'links' => ['https://www.instagram.com/lbaraka.style/'],
                        'description' => 'Défilé à la maison, lumière naturelle, montrer la broderie de près.'],
                    'second' => ['name' => 'Kit Ghassoul & Savon Beldi', 'price' => 129, 'links' => ['https://www.instagram.com/lbaraka.maison/'],
                        'description' => 'Routine hammam traditionnelle, voix off en darija.'],
                    'third' => ['name' => 'Service à Thé Marocain 6 verres', 'links' => ['https://www.instagram.com/lbaraka.maison/'],
                        'description' => 'Ambiance Ftour : thé versé de haut, plateau doré.',
                        'note' => 'On prépare le Ramadan, misez sur la table du Ftour.'],
                    'pack' => ['name' => 'Pack Mariage Babouches & Sac', 'links' => ['https://www.instagram.com/lbaraka.style/', 'https://www.facebook.com/lbaraka.style'],
                        'description' => 'Tenue complète pour les fêtes : babouches assorties au sac.',
                        'note' => 'Push du jour : 2 vidéos look de fête.'],
                    'testing' => ['name' => 'Djellaba Homme Lin', 'links' => ['https://www.instagram.com/lbaraka.style/'],
                        'description' => 'Produit en test pour l\'Aïd : 3 angles différents.',
                        'directions' => ['Angle "Aïd" : sortie de la mosquée en famille', 'Angle "confort" : le lin en été', '']],
                    'inactive' => ['name' => 'Tapis de Prière Mousse', 'links' => ['https://www.instagram.com/lbaraka.maison/'], 'description' => null],
                ],
            ],
        ];
    }

    /** Start a business from a clean slate: nothing carries over from the previous one. */
    private function resetState(array $profile): void
    {
        $this->profile = $profile;
        $this->agents = [];
        $this->fulfilmentAgents = [];
        $this->deliveryGuys = [];
        $this->deliveryGuyRates = [];
        $this->creativesEditors = [];
        $this->stores = [];
        $this->deliveryAccounts = [];
        $this->products = [];
        $this->variantsByProduct = [];
        $this->customers = [];
        $this->loyalCustomers = [];
        $this->blacklistedCustomers = [];
    }

    /**
     * The business's fixed logins (see businesses(), all "password") plus
     * a few colleagues so team reports have variety.
     */
    private function seedUsers(Business $business): void
    {
        $staff = fn (string $email, string $name, UserRole $role, array $extra = []) => User::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
            'email' => $email,
            'phone' => MoroccanData::phone(),
            'password' => 'password',
            'role' => $role,
            ...$extra,
        ]);

        $colleague = function (bool $female): array {
            do {
                $person = MoroccanData::person($female);
            } while (in_array($person['name'], $this->usedNames, true));

            $this->usedNames[] = $person['name'];

            return $person;
        };

        $login = $this->profile['logins'];

        $staff(...$login['admin'], role: UserRole::ADMIN);

        $this->agents[] = $staff(...$login['agent'], role: UserRole::CONFIRMATION_AGENT);

        foreach ([true, true, false] as $female) {
            $person = $colleague($female);
            $this->agents[] = $staff(MoroccanData::staffEmail($person), $person['name'], UserRole::CONFIRMATION_AGENT);
        }

        $this->fulfilmentAgents[] = $staff(...$login['fulfilment'], role: UserRole::FULFILMENT_AGENT);
        $person = $colleague(false);
        $this->fulfilmentAgents[] = $staff(MoroccanData::staffEmail($person), $person['name'], UserRole::FULFILMENT_AGENT);

        // Our own delivery guys, one per city we deliver ourselves.
        $first = true;

        foreach ($this->profile['delivery_cities'] as $city => $rate) {
            [$email, $name] = $first ? $login['delivery'] : [null, null];
            $first = false;

            if ($name === null) {
                $person = $colleague(false);
                [$email, $name] = [MoroccanData::staffEmail($person), $person['name']];
            }

            $guy = $staff($email, $name, UserRole::DELIVERY_GUY, ['delivery_city' => $city]);
            $this->deliveryGuys[$city] = $guy;
            $this->deliveryGuyRates[$guy->id] = (float) $rate;
        }

        // The video / static makers who work the Creatives module.
        $this->creativesEditors[] = $staff(...$login['creatives'], role: UserRole::CREATIVES_EDITOR);
        $person = $colleague(false);
        $this->creativesEditors[] = $staff(MoroccanData::staffEmail($person), $person['name'], UserRole::CREATIVES_EDITOR);
    }

    private function seedStores(Business $business): void
    {
        if ($this->profile['manual_stores'] !== null) {
            $this->seedManualStores($business);

            return;
        }

        $stores = [
            'Atlas Beauty' => EcomPlatform::SHOPIFY,
            'Bladi Shop' => EcomPlatform::YOUCAN,
            'Souk Pro' => EcomPlatform::STOREEP,
            'Dar Deals' => EcomPlatform::WOOCOMMERCE,
            'Casa Gadgets' => EcomPlatform::LIGHTFUNNELS,
        ];

        foreach ($stores as $name => $platform) {
            $platformModel = EcommercePlatform::where('slug', $platform->value)->first();

            if ($platformModel === null) {
                continue;
            }

            $this->stores[] = Store::factory()->create([
                'business_id' => $business->id,
                'platform_id' => $platformModel->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'domain' => Str::slug($name).'.ma',
                'description' => 'Boutique en ligne, paiement à la livraison partout au Maroc.',
            ]);
        }
    }

    /**
     * Manual stores, exactly as StoreController::storeManual() makes them:
     * the "manual" platform, no domain or credentials, status manual.
     */
    private function seedManualStores(Business $business): void
    {
        $platform = EcommercePlatform::firstOrCreate(['slug' => 'manual'], ['name' => 'Manual']);

        foreach ($this->profile['manual_stores'] as $name) {
            $this->stores[] = Store::create([
                'business_id' => $business->id,
                'platform_id' => $platform->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'api_credentials' => '[]',
                'connection_status' => StoreConnectionStatus::MANUAL,
            ]);
        }
    }

    /** True when the current business has no online shop at all. */
    private function manualOnly(): bool
    {
        return $this->profile['manual_stores'] !== null;
    }

    private function seedDeliveryAccounts(Business $business): void
    {
        $couriers = DeliveryCourrier::all();

        foreach ($couriers as $i => $courier) {
            $this->deliveryAccounts[] = DeliveryAccount::factory()->create([
                'business_id' => $business->id,
                'courier_id' => $courier->id,
                'label' => $courier->name.' - Compte principal',
                'is_default' => $i === 0,
                'status' => DeliveryAccountStatus::ACTIVE,
            ]);
        }
    }

    private function seedProducts(Business $business): void
    {
        $names = $this->profile['catalogue'];
        $catalogue = $names === null
            ? MoroccanData::PRODUCTS
            : array_values(array_filter(MoroccanData::PRODUCTS, fn (array $item) => in_array($item['name'], $names, true)));

        foreach ($catalogue as $i => $item) {
            // A quarter of the catalogue is manual-only; the rest is
            // synced from one of the stores. A business with manual stores
            // only has nothing to sync from: every product is manual.
            $store = $this->manualOnly() || $i % 4 === 0 || $this->stores === [] ? null : $this->stores[$i % count($this->stores)];
            $isTest = str_starts_with($item['name'], 'Produit Test');

            $product = Product::factory()->create([
                'business_id' => $business->id,
                'store_id' => $store?->id,
                'external_product_id' => $store ? (string) (7100000 + $i) : null,
                'name' => $item['name'],
                'sku' => $this->profile['sku_prefix'].'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'description' => 'Livraison partout au Maroc, paiement à la réception.',
                'price' => $item['price'],
                'inventory_quantity' => rand(20, 250),
                'is_test' => $isTest,
            ]);

            $this->products[] = $product;

            for ($img = 0; $img < rand(1, 3); $img++) {
                $product->images()->create([
                    'business_id' => $business->id,
                    'path' => "https://picsum.photos/seed/product-{$product->id}-{$img}/600/600",
                    'position' => $img,
                ]);
            }

            if (! isset($item['axis'])) {
                continue;
            }

            [$axisName, $values] = $item['axis'];

            $option = ProductOption::factory()->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'name' => $axisName,
                'position' => 0,
            ]);

            foreach ($values as $pos => $value) {
                $optionValue = ProductOptionValue::factory()->create([
                    'business_id' => $business->id,
                    'product_option_id' => $option->id,
                    'value' => $value,
                    'position' => $pos,
                ]);

                $variant = ProductVariant::factory()->create([
                    'business_id' => $business->id,
                    'product_id' => $product->id,
                    'external_variant_id' => $store ? (string) (100000 + $product->id * 10 + $pos) : null,
                    'sku' => $product->sku.'-'.strtoupper(Str::substr(Str::ascii($value), 0, 3)),
                    'price' => $product->price,
                    'inventory_quantity' => rand(5, 80),
                    'position' => $pos,
                ]);

                $variant->optionValues()->attach($optionValue->id);
                $this->variantsByProduct[$product->id][] = ['variant' => $variant, 'value' => $value];

                $variant->stockMovements()->create([
                    'business_id' => $business->id,
                    'type' => 'restock',
                    'quantity_change' => $variant->inventory_quantity,
                    'note' => 'Stock initial',
                ]);
            }
        }
    }

    /**
     * The people who order. Built first so orders reuse them: a real shop
     * sees the same phone number come back, and some numbers are known
     * troublemakers.
     */
    private function seedCustomers(Business $business): void
    {
        $makeCustomer = function () use ($business): Customer {
            $phone = MoroccanData::phone();
            $city = MoroccanData::city();

            return Customer::create([
                'business_id' => $business->id,
                'name' => MoroccanData::name(),
                'phone' => $phone,
                'phone_hash' => hash('sha256', $phone),
                'address' => MoroccanData::address($city),
                'city' => $city,
            ]);
        };

        for ($i = 0; $i < $this->profile['customers']; $i++) {
            $this->customers[] = $makeCustomer();
        }

        $this->loyalCustomers = array_slice($this->customers, 0, 15);

        $reasons = ['Refus répétés à la livraison', 'Commandes fausses', 'Fraude suspectée', 'Comportement insultant', 'Numéro injoignable à chaque fois'];

        for ($i = 0; $i < 8; $i++) {
            $customer = $makeCustomer();
            $customer->update(['is_blacklisted' => true]);
            $this->blacklistedCustomers[] = $customer;

            CustomerBlacklistEntry::factory()->create([
                'business_id' => $business->id,
                'phone_hash' => $customer->phone_hash,
                'phone_encrypted' => $customer->phone,
                'reason' => fake()->randomElement($reasons),
                'added_by_user_id' => $this->agents[0]->id,
            ]);
        }
    }

    private function seedAgentScopes(Business $business): void
    {
        // First confirmation agent scoped to a couple of stores; others unscoped (see all).
        foreach (array_slice($this->stores, 0, 2) as $store) {
            AgentScope::factory()->create([
                'business_id' => $business->id,
                'user_id' => $this->agents[0]->id,
                'store_id' => $store->id,
            ]);
        }
    }

    private function seedOrders(Business $business): void
    {
        $now = Carbon::now();
        $totalOrders = $this->profile['orders'];
        $codes = app(OrderCodeGenerator::class);

        // Orders still being called are recent; an order from last month
        // has long been confirmed, cancelled or given up on.
        $recentConfirmation = [
            OrderConfirmationStatus::SUBMITTED_TO_COURIER->value => 40,
            OrderConfirmationStatus::CONFIRMED->value => 8,
            OrderConfirmationStatus::CANCELLED->value => 10,
            OrderConfirmationStatus::NEW->value => 10,
            OrderConfirmationStatus::ASSIGNED->value => 8,
            OrderConfirmationStatus::NO_ANSWER->value => 8,
            OrderConfirmationStatus::CALLBACK->value => 5,
            OrderConfirmationStatus::VOICEMAIL->value => 3,
            OrderConfirmationStatus::BUSY->value => 3,
            OrderConfirmationStatus::WHATSAPP_SENT->value => 3,
            OrderConfirmationStatus::FAKE->value => 1,
            OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value => 1,
        ];

        $oldConfirmation = [
            OrderConfirmationStatus::SUBMITTED_TO_COURIER->value => 66,
            OrderConfirmationStatus::CANCELLED->value => 18,
            OrderConfirmationStatus::FAKE->value => 5,
            OrderConfirmationStatus::NO_ANSWER->value => 6,
            OrderConfirmationStatus::CONFIRMED->value => 5,
        ];

        // A known troublemaker rarely makes it to the door.
        $blacklistedConfirmation = [
            OrderConfirmationStatus::FAKE->value => 40,
            OrderConfirmationStatus::CANCELLED->value => 30,
            OrderConfirmationStatus::NO_ANSWER->value => 30,
        ];

        // Manual orders: how they reached us.
        $channels = [
            OrderSource::WHATSAPP->value => 40,
            OrderSource::INSTAGRAM->value => 20,
            OrderSource::FACEBOOK->value => 15,
            OrderSource::TIKTOK->value => 12,
            OrderSource::PHONE_CALL->value => 10,
            OrderSource::OTHER->value => 3,
        ];

        // People order in the evening, after work.
        $hours = [8 => 1, 9 => 2, 10 => 4, 11 => 5, 12 => 5, 13 => 6, 14 => 6, 15 => 5, 16 => 5, 17 => 6, 18 => 7, 19 => 8, 20 => 10, 21 => 10, 22 => 8, 23 => 5, 0 => 2];

        for ($i = 0; $i < $totalOrders; $i++) {
            $daysAgo = rand(0, 89);
            $orderedAt = $now->copy()->subDays($daysAgo)->setTime((int) $this->weightedRandom($hours), rand(0, 59), rand(0, 59));

            if ($orderedAt->greaterThan($now)) {
                $orderedAt = $now->copy()->subMinutes(rand(5, 120));
            }

            $customer = $this->pickCustomer();
            $isBlacklisted = (bool) $customer->is_blacklisted;

            if ($this->manualOnly()) {
                // Typed in by hand for one of the brand's manual stores;
                // the channel is where the customer reached it.
                $fromStore = false;
                $store = fake()->randomElement($this->stores);
            } else {
                $fromStore = $this->stores !== [] && fake()->boolean(72);
                $store = $fromStore ? fake()->randomElement($this->stores) : (fake()->boolean(50) && $this->stores !== [] ? fake()->randomElement($this->stores) : null);
            }
            $agent = fake()->randomElement($this->agents);
            $isTest = fake()->boolean(2);

            $confirmationStatus = $this->weightedRandom(match (true) {
                $isBlacklisted => $blacklistedConfirmation,
                $daysAgo <= 3 => $recentConfirmation,
                default => $oldConfirmation,
            });

            // A customer moves house now and then: most orders reuse their
            // address, some give a new one in the same city.
            $address = fake()->boolean(85) ? $customer->address : MoroccanData::address($customer->city);

            $order = new Order;
            $order->reference = $codes->reference();
            $order->business_id = $business->id;
            $order->store_id = $store?->id;
            $order->external_order_id = $fromStore ? (string) (1000 + $i) : null;
            $order->source_platform = $fromStore ? $store->platform->slug : $this->weightedRandom($channels);
            $order->assigned_agent_id = $confirmationStatus === OrderConfirmationStatus::NEW->value && $fromStore ? null : $agent->id;
            $order->customer_name = $customer->name;
            $order->customer_phone = $customer->phone;
            $order->customer_phone_hash = $customer->phone_hash;
            $order->customer_address = $address;
            $order->customer_city = $customer->city;
            $order->customer_ip_address = $fromStore ? MoroccanData::ip() : null;
            $order->notes = fake()->boolean(15) ? MoroccanData::orderNote() : null;
            $order->confirmation_status = OrderConfirmationStatus::from($confirmationStatus);
            $order->is_test = $isTest;
            $order->is_blacklist_flagged = $isBlacklisted;
            $order->ordered_at = $orderedAt;
            $order->created_at = $orderedAt;
            $order->updated_at = $orderedAt;

            if ($confirmationStatus === OrderConfirmationStatus::CANCELLED->value) {
                $order->cancellation_reason_code = fake()->randomElement(OrderCancelReason::cases())->value;
            }

            $outcomeAt = null;

            if ($confirmationStatus === OrderConfirmationStatus::SUBMITTED_TO_COURIER->value) {
                $guy = $this->deliveryGuys[$customer->city] ?? null;
                $ownDelivery = $guy !== null && fake()->boolean(60);

                $order->shipped_at = $this->notAfterNow($orderedAt->copy()->addHours(rand(2, 30)));
                $order->ready_for_pickup_at = $order->shipped_at->copy()->subHours(rand(1, 6));

                $deliveryStatus = $ownDelivery
                    ? $this->ownDeliveryStatus($daysAgo)
                    : $this->courierDeliveryStatus($daysAgo);

                $order->delivery_status = OrderDeliveryStatus::from($deliveryStatus);
                $order->is_delivery_active = ! in_array($order->delivery_status, [OrderDeliveryStatus::DELIVERED, OrderDeliveryStatus::CANCELLED_AT_COURIER], true);

                if (in_array($order->delivery_status, [OrderDeliveryStatus::DELIVERED, OrderDeliveryStatus::REFUSED, OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED], true)) {
                    // Our guys settle a parcel the same or next day; a
                    // courier takes one to four.
                    $outcomeAt = $this->notAfterNow($ownDelivery
                        ? $order->shipped_at->copy()->addHours(rand(3, 30))
                        : $order->shipped_at->copy()->addDays(rand(1, 4))->addMinutes(rand(0, 600)));
                }

                if (in_array($order->delivery_status, [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED, OrderDeliveryStatus::REFUSED], true)) {
                    $order->return_reason_code = fake()->randomElement(OrderReturnReason::cases())->value;
                }

                if ($order->delivery_status === OrderDeliveryStatus::RETURN_RECEIVED) {
                    $order->return_received_at = $this->notAfterNow(($outcomeAt ?? $order->shipped_at)->copy()->addDays(rand(1, 3)));
                }

                if ($ownDelivery) {
                    $order->delivery_guy_id = $guy->id;
                    $order->courier_tracking_number = $codes->localTracking($guy->delivery_city);
                    $order->courier_slug = 'own';
                    // His pay is the parcel's delivery cost, once delivered.
                    $order->delivery_cost = $order->delivery_status === OrderDeliveryStatus::DELIVERED ? $this->deliveryGuyRates[$guy->id] : 0;
                    $order->returned_cost = 0;
                    $order->refused_cost = 0;
                } else {
                    $deliveryAccount = fake()->randomElement($this->deliveryAccounts);
                    $order->delivery_account_id = $deliveryAccount->id;
                    $order->courier_tracking_number = strtoupper(Str::random(3)).fake()->numerify('#########');
                    $order->courier_slug = $deliveryAccount->courier->slug;
                    $order->delivery_cost = $customer->city === 'Casablanca' ? 20 : fake()->randomElement([30, 35, 35, 40]);

                    if ($order->delivery_status === OrderDeliveryStatus::OUT_FOR_DELIVERY) {
                        $order->delivery_driver_name = MoroccanData::name(false);
                        $order->delivery_driver_phone = MoroccanData::phone();
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
                }

                $order->updated_at = $outcomeAt ?? $order->shipped_at;
            } elseif ($confirmationStatus === OrderConfirmationStatus::CONFIRMED->value && $daysAgo >= 3 && fake()->boolean(30)) {
                // Some confirmed-but-not-shipped orders older than 3 days => stranded "awaiting shipment".
                $order->ordered_at = $now->copy()->subDays(rand(3, 6));
            }

            $order->total_amount = 1; // placeholder, recalculated after items below
            $order->save();

            $this->seedOrderItems($business, $order, $store);
            $this->buildStatusTrail($order, $agent, $outcomeAt);
        }
    }

    /** Mostly new faces, some regulars, the odd blacklisted number. */
    private function pickCustomer(): Customer
    {
        return match (true) {
            fake()->boolean(3) && $this->blacklistedCustomers !== [] => fake()->randomElement($this->blacklistedCustomers),
            fake()->boolean(15) => fake()->randomElement($this->loyalCustomers),
            default => fake()->randomElement($this->customers),
        };
    }

    /** Where a courier parcel stands, by how old its order is. */
    private function courierDeliveryStatus(int $daysAgo): string
    {
        return $this->weightedRandom(match (true) {
            $daysAgo < 2 => [
                OrderDeliveryStatus::AWAITING_PICKUP->value => 40,
                OrderDeliveryStatus::READY_FOR_PICKUP->value => 30,
                OrderDeliveryStatus::IN_TRANSIT->value => 30,
            ],
            $daysAgo <= 10 => [
                OrderDeliveryStatus::DELIVERED->value => 45,
                OrderDeliveryStatus::IN_TRANSIT->value => 14,
                OrderDeliveryStatus::OUT_FOR_DELIVERY->value => 10,
                OrderDeliveryStatus::POSTPONED->value => 5,
                OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED->value => 4,
                OrderDeliveryStatus::REFUSED->value => 8,
                OrderDeliveryStatus::RETURNED_IN_TRANSIT->value => 6,
                OrderDeliveryStatus::RETURN_RECEIVED->value => 4,
                OrderDeliveryStatus::READY_FOR_PICKUP->value => 3,
                OrderDeliveryStatus::CANCELLED_AT_COURIER->value => 1,
            ],
            default => [
                OrderDeliveryStatus::DELIVERED->value => 74,
                OrderDeliveryStatus::REFUSED->value => 6,
                OrderDeliveryStatus::RETURNED_IN_TRANSIT->value => 3,
                OrderDeliveryStatus::RETURN_RECEIVED->value => 15,
                OrderDeliveryStatus::CANCELLED_AT_COURIER->value => 2,
            ],
        });
    }

    /** Where one of our own delivery guy's parcels stands. */
    private function ownDeliveryStatus(int $daysAgo): string
    {
        return $this->weightedRandom(match (true) {
            $daysAgo < 2 => [
                OrderDeliveryStatus::AWAITING_PICKUP->value => 35,
                OrderDeliveryStatus::READY_FOR_PICKUP->value => 30,
                OrderDeliveryStatus::OUT_FOR_DELIVERY->value => 35,
            ],
            $daysAgo <= 10 => [
                OrderDeliveryStatus::DELIVERED->value => 60,
                OrderDeliveryStatus::OUT_FOR_DELIVERY->value => 8,
                OrderDeliveryStatus::POSTPONED->value => 6,
                OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED->value => 6,
                OrderDeliveryStatus::REFUSED->value => 12,
                OrderDeliveryStatus::RETURN_RECEIVED->value => 8,
            ],
            default => [
                OrderDeliveryStatus::DELIVERED->value => 82,
                OrderDeliveryStatus::REFUSED->value => 4,
                OrderDeliveryStatus::RETURN_RECEIVED->value => 14,
            ],
        });
    }

    /** Mostly one item, sometimes two, rarely three — as COD orders go. */
    private function seedOrderItems(Business $business, Order $order, ?Store $store): void
    {
        $eligibleProducts = $store
            ? array_values(array_filter($this->products, fn (Product $p) => $p->store_id === $store->id && ! $p->is_test))
            : array_values(array_filter($this->products, fn (Product $p) => ! $p->is_test));

        if ($order->is_test) {
            $eligibleProducts = array_values(array_filter($this->products, fn (Product $p) => $p->is_test)) ?: $this->products;
        } elseif ($eligibleProducts === []) {
            $eligibleProducts = $this->products;
        }

        $itemCount = (int) $this->weightedRandom([1 => 78, 2 => 18, 3 => 4]);
        $chosenProducts = fake()->randomElements($eligibleProducts, min($itemCount, count($eligibleProducts)));
        $total = 0;
        $lineItems = [];

        foreach ($chosenProducts as $product) {
            $variants = $this->variantsByProduct[$product->id] ?? [];
            $picked = $variants !== [] ? fake()->randomElement($variants) : null;
            $variant = $picked['variant'] ?? null;
            $qty = (int) $this->weightedRandom([1 => 82, 2 => 15, 3 => 3]);
            $unitPrice = (float) ($variant->price ?? $product->price);

            $lineItems[] = [
                'business_id' => $business->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name_snapshot' => $picked ? "{$product->name} ({$picked['value']})" : $product->name,
                'sku_snapshot' => $variant->sku ?? $product->sku,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'created_at' => $order->ordered_at,
                'updated_at' => $order->ordered_at,
            ];

            $total += $qty * $unitPrice;

            if ($variant && $order->delivery_status === OrderDeliveryStatus::DELIVERED) {
                $variant->stockMovements()->create([
                    'business_id' => $business->id,
                    'type' => 'sale',
                    'quantity_change' => -$qty,
                    'note' => 'Commande '.$order->reference,
                    'created_at' => $order->shipped_at ?? $order->ordered_at,
                ]);
            }
        }

        OrderItem::insert($lineItems);
        $order->total_amount = round($total, 2);
        $order->save();
    }

    private function buildStatusTrail(Order $order, User $agent, ?CarbonInterface $outcomeAt): void
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
            $cursor = $this->notAfterNow($cursor->copy()->addMinutes(rand(5, 90)));
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
                $cursor = $this->notAfterNow($cursor->copy()->addMinutes(rand(30, 240)));
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
                $cursor = $this->notAfterNow($cursor->copy()->addMinutes(rand(10, 180)));
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

            $cursor = $this->notAfterNow($cursor->copy()->addMinutes(rand(10, 180)));
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
            // Terminal outcomes happen after pickup, not the moment the
            // parcel ships — this gap is what delivery_seconds_total
            // (per-courier "avg days") rebuilds from.
            $events[] = [
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'from_status' => $order->confirmation_status->value,
                'to_status' => $order->delivery_status->value,
                'changed_by_user_id' => $order->delivery_guy_id,
                'note' => null,
                'created_at' => $outcomeAt ?? $this->notAfterNow(($order->shipped_at ?? $cursor)->copy()->addHours(1)),
            ];
        }

        OrderStatusEvent::insert($events);
    }

    /** What each customer really did, read back from the seeded orders. */
    private function refreshCustomerCounts(): void
    {
        foreach ([...$this->customers, ...$this->blacklistedCustomers] as $customer) {
            $orders = Order::where('business_id', $customer->business_id)
                ->where('customer_phone_hash', $customer->phone_hash)
                ->get(['delivery_status', 'ordered_at']);

            $delivered = $orders->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();

            $customer->update([
                'orders_count' => $orders->count(),
                'delivered_orders_count' => $delivered,
                'returned_orders_count' => $orders->filter(fn (Order $o) => in_array($o->delivery_status, [
                    OrderDeliveryStatus::REFUSED,
                    OrderDeliveryStatus::RETURNED_IN_TRANSIT,
                    OrderDeliveryStatus::RETURN_RECEIVED,
                ], true))->count(),
                'last_order_at' => $orders->max('ordered_at'),
                'is_best_customer' => $delivered >= 3,
            ]);
        }
    }

    private function seedCommissions(Business $business): void
    {
        foreach ($this->agents as $agent) {
            CommissionRule::factory()->create([
                'business_id' => $business->id,
                'user_id' => $agent->id,
                'payment_mode' => CommissionPaymentMode::COMMISSION,
                'amount' => fake()->randomElement([8, 10, 12, 15]),
            ]);
        }

        foreach ($this->fulfilmentAgents as $fulfilmentAgent) {
            CommissionRule::factory()->salary()->create([
                'business_id' => $business->id,
                'user_id' => $fulfilmentAgent->id,
                'salary_amount' => fake()->randomElement([3000, 3500, 4000]),
            ]);
        }

        foreach ($this->agents as $agent) {
            $rule = CommissionRule::where('business_id', $business->id)->where('user_id', $agent->id)->first();

            $orders = Order::where('business_id', $business->id)
                ->where('assigned_agent_id', $agent->id)
                ->where('is_test', false)
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
            $amount = (float) $rule->amount;

            foreach ($orders as $order) {
                $isPastPeriod = $pastOrders->contains('id', $order->id);

                $ledgerRows[] = [
                    'business_id' => $business->id,
                    'user_id' => $agent->id,
                    'order_id' => $order->id,
                    'commission_rule_id' => $rule->id,
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
                    'total_amount' => round($currentOrders->count() * $amount, 2),
                    'status' => 'draft',
                ]);
            }
        }
    }

    /**
     * Each delivery guy's pay per delivered parcel, and his weekly cash
     * hand-overs: every week before last is settled, the same way the
     * settlements page does it, one of them short so a balance carries.
     */
    private function seedDeliveryGuyPay(Business $business): void
    {
        $admin = User::where('business_id', $business->id)->where('role', UserRole::ADMIN)->first();

        foreach ($this->deliveryGuys as $guy) {
            $rate = $this->deliveryGuyRates[$guy->id];

            $rule = CommissionRule::factory()->onDelivery()->create([
                'business_id' => $business->id,
                'user_id' => $guy->id,
                'payment_mode' => CommissionPaymentMode::COMMISSION,
                'amount' => $rate,
            ]);

            $delivered = Order::where('business_id', $business->id)
                ->where('delivery_guy_id', $guy->id)
                ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
                ->get()
                ->each(function (Order $order) {
                    $order->delivered_at = OrderStatusEvent::where('order_id', $order->id)
                        ->where('to_status', OrderDeliveryStatus::DELIVERED->value)
                        ->value('created_at');
                })
                ->sortBy('delivered_at');

            CommissionLedgerEntry::insert($delivered->where('is_test', false)->map(fn (Order $order) => [
                'business_id' => $business->id,
                'user_id' => $guy->id,
                'order_id' => $order->id,
                'commission_rule_id' => $rule->id,
                'invoice_id' => null,
                'amount' => $rate,
                'entry_type' => 'earned',
                'reversed_entry_id' => null,
                'description' => 'Parcel delivered',
                'created_at' => $order->delivered_at,
            ])->values()->all());

            $lastWeek = Carbon::now()->subWeek()->startOfWeek();
            $weeks = $delivered
                ->filter(fn (Order $order) => Carbon::parse($order->delivered_at)->lessThan($lastWeek))
                ->groupBy(fn (Order $order) => Carbon::parse($order->delivered_at)->startOfWeek()->toDateString());

            $carried = 0.0;
            $shortWeek = $weeks->isEmpty() ? null : $weeks->keys()->random();

            foreach ($weeks as $week => $parcels) {
                $expected = round($parcels->sum(fn (Order $o) => (float) $o->total_amount - (float) $o->delivery_cost) + $carried, 2);
                $received = $week === $shortWeek ? round($expected - fake()->randomElement([50, 100, 150]), 2) : $expected;
                $difference = round($received - $expected, 2);

                $settlement = DeliveryCashSettlement::create([
                    'business_id' => $business->id,
                    'user_id' => $guy->id,
                    'settled_by_user_id' => $admin?->id,
                    'amount' => $expected,
                    'received_amount' => $received,
                    'difference_amount' => $difference,
                    'balance_after' => abs($difference) < 0.01 ? 0 : round(-$difference, 2),
                    'parcels_count' => $parcels->count(),
                    'status' => abs($difference) < 1 ? 'reconciled' : 'disputed',
                    'note' => $difference < 0 ? 'Il manque une partie, à rendre la semaine prochaine.' : null,
                ]);

                $settlement->forceFill(['created_at' => Carbon::parse($week)->endOfWeek()->setTime(18, 0)])->save();

                Order::whereIn('id', $parcels->pluck('id'))->update(['delivery_cash_settlement_id' => $settlement->id]);

                $carried = (float) $settlement->balance_after;
            }
        }
    }

    /**
     * The Creatives module, following the same rules ContentRequestService
     * enforces: product briefs assigned to editors, a history of validated
     * work that paid the editor through a 'creative' commission entry (the
     * older ones already paid through an invoice), the products' counters
     * kept in step with that history, and a live review queue in every
     * state — sent, returned, sent back for edits, and an editor's own
     * daily push on an active product.
     */
    private function seedCreatives(Business $business): void
    {
        if ($this->creativesEditors === []) {
            return;
        }

        [$hiba, $second] = [$this->creativesEditors[0], $this->creativesEditors[1] ?? $this->creativesEditors[0]];

        $briefs = $this->profile['creatives'];

        // role => [status, kind, editors working it]
        $roles = [
            'hero' => [CreativeProductStatus::ACTIVE, 'single', [$hiba, $second]],
            'second' => [CreativeProductStatus::ACTIVE, 'single', [$hiba]],
            'third' => [CreativeProductStatus::ACTIVE, 'single', [$second]],
            'pack' => [CreativeProductStatus::ACTIVE, 'pack', [$hiba, $second]],
            'testing' => [CreativeProductStatus::TESTING, 'single', [$hiba]],
            'inactive' => [CreativeProductStatus::INACTIVE, 'single', [$second]],
        ];

        $products = [];

        foreach ($roles as $role => [$status, $kind, $editors]) {
            $brief = $briefs[$role];

            $product = CreativeProduct::create([
                'business_id' => $business->id,
                'name' => $brief['name'],
                'kind' => $kind,
                'links' => $brief['links'],
                'description' => $brief['description'],
                'status' => $status,
            ]);

            foreach ($editors as $editor) {
                $product->editors()->syncWithoutDetaching([$editor->id => ['business_id' => $business->id]]);
            }

            $products[$role] = [$product, $editors];
        }

        // History: validated work, newest first, never on the testing product.
        foreach ($products as [$product, $editors]) {
            if ($product->status === CreativeProductStatus::TESTING) {
                continue;
            }

            // An inactive product stopped getting work a while ago.
            $window = $product->status === CreativeProductStatus::INACTIVE ? [45, 85] : [2, 75];

            for ($i = 0, $n = rand(3, 5); $i < $n; $i++) {
                $validatedAt = Carbon::now()->subDays(rand(...$window))->setTime(rand(10, 19), rand(0, 59));
                $editor = $editors[array_rand($editors)];
                $videos = rand(2, 5);
                $statics = rand(0, 1) === 1 ? rand(2, 4) : 0;
                $reworked = rand(1, 4) === 1;

                $request = $this->creativeRequest($business, $product, $editor, [
                    'origin' => $product->status === CreativeProductStatus::ACTIVE && rand(1, 3) === 1 ? 'editor' : 'admin',
                    'status' => ContentRequestStatus::VALIDATED,
                    'rev' => $reworked ? 2 : 1,
                    'direction_points' => $reworked ? ['Raccourcir l\'intro à 3 secondes', 'Ajouter le prix à l\'écran'] : [],
                    'drive_url' => $this->driveUrl(),
                    'returned_at' => $validatedAt->copy()->subHours(rand(2, 20)),
                    'edits_requested_at' => $reworked ? $validatedAt->copy()->subDays(1) : null,
                    'validated_at' => $validatedAt,
                    'created_at' => $validatedAt->copy()->subDays(rand(2, 5)),
                ], $this->creativeItems($videos, $statics));

                // Editor pay: 120 MAD a video, 40 MAD a static, written the way
                // ContentRequestService::validate() writes it.
                $amount = $videos * 120 + $statics * 40;
                $entry = (new CommissionLedgerEntry)->forceFill([
                    'business_id' => $business->id,
                    'user_id' => $editor->id,
                    'order_id' => null,
                    'content_request_id' => $request->id,
                    'amount' => $amount,
                    'entry_type' => 'creative',
                    'description' => $product->name.' · '.$request->load('items')->label(),
                    'created_at' => $validatedAt,
                ]);
                $entry->save();

                // Work older than a month has been paid, the way the admin's
                // "Pay" button does it: one invoice per entry, marked paid.
                if ($validatedAt->lt(Carbon::now()->subDays(30))) {
                    $invoice = Invoice::create([
                        'business_id' => $business->id,
                        'user_id' => $editor->id,
                        'invoice_number' => 'INV-'.$validatedAt->format('Ymd').'-'.Str::upper(Str::random(6)),
                        'period_start' => $validatedAt->toDateString(),
                        'period_end' => $validatedAt->toDateString(),
                        'total_amount' => $amount,
                        'status' => 'paid',
                    ]);

                    $entry->forceFill(['invoice_id' => $invoice->id])->save();
                }

                $product->works_count += $videos + $statics;
                $product->last_push_at = $product->last_push_at === null || $validatedAt->gt($product->last_push_at)
                    ? $validatedAt
                    : $product->last_push_at;
            }

            $product->save();
        }

        // The live queue, one request in each state the review board shows.
        [$testing] = $products['testing'];
        $this->creativeRequest($business, $testing, $hiba, [
            'status' => ContentRequestStatus::SENT,
            'admin_note' => 'Test produit : on cherche l\'angle qui accroche le mieux.',
            'created_at' => Carbon::now()->subHours(20),
        ], $this->creativeItems(3, 0, $briefs['testing']['directions']));

        [$third] = $products['third'];
        $this->creativeRequest($business, $third, $second, [
            'status' => ContentRequestStatus::SENT,
            'admin_note' => $briefs['third']['note'],
            'created_at' => Carbon::now()->subDays(2),
        ], $this->creativeItems(2, 2));

        [$hero] = $products['hero'];
        $this->creativeRequest($business, $hero, $hiba, [
            'status' => ContentRequestStatus::RETURNED,
            'drive_url' => $this->driveUrl(),
            'editor_note' => 'Deux versions de la vidéo 2, choisissez celle qui vous plaît.',
            'returned_at' => Carbon::now()->subHours(3),
            'created_at' => Carbon::now()->subDays(3),
        ], $this->creativeItems(3, 2));

        [$secondProduct] = $products['second'];
        $this->creativeRequest($business, $secondProduct, $hiba, [
            'status' => ContentRequestStatus::EDITS,
            'rev' => 2,
            'drive_url' => $this->driveUrl(),
            'direction_points' => [
                'Le logo doit apparaître dès la première seconde',
                'Sous-titres en darija, pas en arabe classique',
                'Finir sur le prix : '.$briefs['second']['price'].' MAD, livraison gratuite',
            ],
            'returned_at' => Carbon::now()->subDays(2),
            'edits_requested_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now()->subDays(4),
        ], $this->creativeItems(2, 0));

        [$pack] = $products['pack'];
        $this->creativeRequest($business, $pack, $second, [
            'origin' => 'editor',
            'status' => ContentRequestStatus::RETURNED,
            'drive_url' => $this->driveUrl(),
            'editor_note' => $briefs['pack']['note'],
            'returned_at' => Carbon::now()->subHours(6),
            'created_at' => Carbon::now()->subHours(6),
        ], $this->creativeItems(2, 0));
    }

    /**
     * One content request with its line items, timestamps forced to the
     * demo timeline rather than "now".
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{type: string, count: int, directions: list<string>}>  $items
     */
    private function creativeRequest(Business $business, CreativeProduct $product, User $editor, array $attributes, array $items): ContentRequest
    {
        $createdAt = $attributes['created_at'] ?? Carbon::now();

        $request = (new ContentRequest)->forceFill([
            'business_id' => $business->id,
            'creative_product_id' => $product->id,
            'editor_id' => $editor->id,
            'origin' => 'admin',
            'rev' => 1,
            'direction_points' => [],
            ...$attributes,
            'created_at' => $createdAt,
            'updated_at' => $attributes['validated_at'] ?? $attributes['edits_requested_at'] ?? $attributes['returned_at'] ?? $createdAt,
        ]);
        $request->save();

        foreach ($items as $item) {
            ContentRequestItem::create(['content_request_id' => $request->id, ...$item]);
        }

        return $request;
    }

    /**
     * Video and static lines; a direction per creative, an empty entry
     * meaning "free style", as the request form stores them.
     *
     * @param  list<string>|null  $videoDirections
     * @return list<array{type: string, count: int, directions: list<string>}>
     */
    private function creativeItems(int $videos, int $statics, ?array $videoDirections = null): array
    {
        $ideas = [
            'Hook en 2 secondes : le problème du client',
            'Témoignage face caméra',
            'Démonstration produit en gros plan',
            'Unboxing + réaction',
            '',
            '',
        ];

        $pick = fn (int $count) => array_map(fn () => $ideas[array_rand($ideas)], range(1, max(1, $count)));

        $items = [];

        if ($videos > 0) {
            $items[] = ['type' => 'video', 'count' => $videos, 'directions' => array_slice($videoDirections ?? $pick($videos), 0, $videos)];
        }

        if ($statics > 0) {
            $items[] = ['type' => 'static', 'count' => $statics, 'directions' => array_slice($pick($statics), 0, $statics)];
        }

        return $items;
    }

    private function driveUrl(): string
    {
        return 'https://drive.google.com/drive/folders/1'.Str::random(32);
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
                    'notes' => 'Écart en cours de vérification avec le transporteur.',
                ]);
            }
        }
    }

    private function seedPerformanceTargets(Business $business): void
    {
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

    /** A timestamp the seeder computed, never later than now. */
    private function notAfterNow(CarbonInterface $at): CarbonInterface
    {
        return $at->greaterThan(Carbon::now()) ? Carbon::now()->subMinutes(rand(1, 30)) : $at;
    }

    /** @param array<int|string, int> $weights */
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
