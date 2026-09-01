<?php

use App\Enums\Courier;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Services\Operations\Couriers\AmeexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: DeliveryAccount, 1: DeliveryCourrier, 2: int}
 */
function makeAmeexAccount(): array
{
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['api_id' => '2', 'api_key' => 'test-key']),
        'status' => 'active',
    ]);

    return [$account, $courier, $admin->business_id];
}

function fakeAmeexAddParcel(string $code = 'MRK0824B2LP5041691'): void
{
    Http::fake([
        'api.ameex.app/customer/Delivery/Parcels/Action/Type/Add' => Http::response([
            'login' => 'success',
            'api' => ['type' => 'success', 'msg' => 'Parcel added', 'parcel' => ['code' => $code]],
        ], 200),
    ]);
}

test('addParcel sends the order to Ameex and returns the parcel code', function () {
    fakeAmeexAddParcel();

    [$account, $courier, $businessId] = makeAmeexAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '1',
    ]);

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'reference' => 'ORD-1042',
        'customer_name' => 'Youssef Alami',
        'customer_phone' => '0600000000',
        'customer_address' => '12 Rue Ibn Batouta',
        'total_amount' => 499,
    ]);

    $parcel = (new AmeexService($account))->addParcel($order, $city);

    expect($parcel->trackingNumber)->toBe('MRK0824B2LP5041691');

    Http::assertSent(function ($request) {
        $body = $request->data();

        // The city ID, not the name — Ameex rejects a plain city name.
        return $body['city'] === '1'
            && $body['receiver'] === 'Youssef Alami'
            && (float) $body['cod'] === 499.0
            // The sender defaults to the account's own API id; omitting it
            // is what triggers "Veuillez choisir l'expéditeur".
            && $body['business'] === '2'
            && $request->hasHeader('C-Api-Id', '2');
    });
});

test('addParcel refuses a city with no Ameex city id', function () {
    // Unlike Coliix and ForceLog, there is no falling back to a city name.
    [$account, $courier, $businessId] = makeAmeexAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 100]);

    (new AmeexService($account))->addParcel($order, $city);
})->throws(InvalidArgumentException::class, 'Ameex requires a destination city with an Ameex city id.');

test('addParcel refuses when no city is passed at all', function () {
    [$account, , $businessId] = makeAmeexAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'customer_city' => 'Casablanca',
        'total_amount' => 100,
    ]);

    (new AmeexService($account))->addParcel($order, null);
})->throws(InvalidArgumentException::class);

test('an operation-level rejection is raised as a RequestException despite the HTTP 200', function () {
    // {"login":"success","api":{"type":"error","msg":"..."}} — authenticated
    // but rejected.
    Http::fake([
        'api.ameex.app/*' => Http::response([
            'login' => 'success',
            'api' => ['type' => 'error', 'msg' => "Veuillez choisir l'expéditeur"],
        ], 200),
    ]);

    [$account, $courier, $businessId] = makeAmeexAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '1',
    ]);
    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 100]);

    (new AmeexService($account))->addParcel($order, $city);
})->throws(RequestException::class);

test('an auth-level rejection is raised as a RequestException despite the HTTP 200', function () {
    // {"login":"error"} — the credentials themselves were refused.
    Http::fake([
        'api.ameex.app/*' => Http::response(['login' => 'error', 'api' => null], 200),
    ]);

    [$account, $courier, $businessId] = makeAmeexAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '1',
    ]);
    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 100]);

    (new AmeexService($account))->addParcel($order, $city);
})->throws(RequestException::class);

test('getCurrentStatus returns the last tracking history entry', function () {
    Http::fake([
        'api.ameex.app/customer/Delivery/Parcels/Tracking*' => Http::response([
            'login' => 'success',
            'api' => [
                'type' => 'success',
                'tracking' => [
                    ['statut' => 'Nouveau Colis', 'date' => '2026-01-01 10:00'],
                    ['statut' => 'Livré', 'date' => '2026-01-03 14:00'],
                ],
            ],
        ], 200),
    ]);

    [$account] = makeAmeexAccount();

    expect((new AmeexService($account))->getCurrentStatus('MRK-1'))->toBe('Livré');
});

test('getCurrentStatus falls back to the payload status when there is no history', function () {
    Http::fake([
        'api.ameex.app/customer/Delivery/Parcels/Tracking*' => Http::response([
            'login' => 'success',
            'api' => ['type' => 'success', 'tracking' => [], 'statut' => 'Ramassé'],
        ], 200),
    ]);

    [$account] = makeAmeexAccount();

    expect((new AmeexService($account))->getCurrentStatus('MRK-1'))->toBe('Ramassé');
});

test('getCurrentStatus returns null when Ameex reports nothing for the code', function () {
    // One unknown parcel must not fail the whole poll run.
    Http::fake([
        'api.ameex.app/customer/Delivery/Parcels/Tracking*' => Http::response([
            'login' => 'success',
            'api' => ['type' => 'error', 'msg' => 'Parcel not found'],
        ], 200),
    ]);

    [$account] = makeAmeexAccount();

    expect((new AmeexService($account))->getCurrentStatus('MRK-UNKNOWN'))->toBeNull();
});

test('getCurrentStatus lets a real HTTP-level failure propagate', function () {
    Http::fake(['api.ameex.app/*' => Http::response(['error' => 'server error'], 500)]);

    [$account] = makeAmeexAccount();

    (new AmeexService($account))->getCurrentStatus('MRK-1');
})->throws(RequestException::class);

test('syncCities on the service points at the CSV-backed command', function () {
    // Ameex publishes no city-list endpoint, so the import reads a static
    // export from the command instead — it needs no account credentials.
    [$account] = makeAmeexAccount();

    (new AmeexService($account))->syncCities();
})->throws(RuntimeException::class, 'couriers:sync-cities --courier=Ameex');

test('the sync-cities command imports Ameex cities from the CSV export', function () {
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value])
        ->assertSuccessful();

    $cities = DeleveryCourrierCity::where('courrier_id', $courier->id);

    expect($cities->count())->toBeGreaterThan(500);

    // Ameex's own numeric id is what addParcel sends as `city`, so every
    // imported row must carry one.
    expect($cities->clone()->whereNull('external_courrier_id')->count())->toBe(0);

    $marrakech = $cities->clone()->where('external_courrier_id', '1')->firstOrFail();
    expect($marrakech->name)->toBe('Marrakech');
});

test('the header row is not imported as a city', function () {
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->where('name', 'Nom')->exists())
        ->toBeFalse();
});

test('accented city names survive the ISO-8859-1 export encoding', function () {
    // The export is ISO-8859-1, not UTF-8 — without conversion these land
    // as mojibake.
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    $city = DeleveryCourrierCity::where('courrier_id', $courier->id)
        ->where('external_courrier_id', '565')
        ->firstOrFail();

    expect($city->name)->toBe('Aït Attab');
});

test('stray quoting and whitespace are stripped from city names', function () {
    // Row 455 arrives as `"Moulay Yaâcoub\t"` in the export.
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    $city = DeleveryCourrierCity::where('courrier_id', $courier->id)
        ->where('external_courrier_id', '455')
        ->firstOrFail();

    expect($city->name)->toBe('Moulay Yaâcoub');
});

test('no delivery fee is imported from the CSV', function () {
    // The export's "Frais" columns are scoped to one pickup city and one
    // account's negotiated rates, so they are deliberately not a source of
    // delivery cost — the cities table has no fee column at all.
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    $city = DeleveryCourrierCity::where('courrier_id', $courier->id)->firstOrFail();

    expect($city->getAttributes())->not->toHaveKey('fee');
    expect($city->getAttributes())->not->toHaveKey('delivery_fee');
});

test('re-running the Ameex city import updates in place rather than duplicating', function () {
    $courier = DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);
    $first = DeleveryCourrierCity::where('courrier_id', $courier->id)->count();

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe($first);
});

test('the Ameex city import is skipped when the courier is not seeded', function () {
    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value])
        ->expectsOutputToContain('not seeded')
        ->assertSuccessful();
});

test('a parcel can be created against a city imported from the CSV', function () {
    // End to end: the CSV-imported external id is what addParcel sends.
    fakeAmeexAddParcel();

    [$account, $courier, $businessId] = makeAmeexAccount();

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::AMEEX->value]);

    $city = DeleveryCourrierCity::where('courrier_id', $courier->id)
        ->where('external_courrier_id', '1')
        ->firstOrFail();

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 499]);

    $parcel = (new AmeexService($account))->addParcel($order, $city);

    expect($parcel->trackingNumber)->toBe('MRK0824B2LP5041691');
    // Ameex quotes no fee anywhere in its API.
    expect($parcel->deliveryCost)->toBeNull();

    Http::assertSent(fn ($request) => $request->data()['city'] === '1');
});
