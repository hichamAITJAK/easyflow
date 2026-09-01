<?php

namespace App\Console\Commands;

use App\DTOs\ForceLog\ForceLogCityDTO;
use App\Enums\Courier;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryCourrier;
use App\Services\Connectivity\ForceLog\ForceLogClient;
use App\Services\Connectivity\OzonExpress\OzonExpressClient;
use App\Services\Connectivity\Sendit\AuthService;
use App\Services\Connectivity\Sendit\DistrictService;
use Illuminate\Console\Command;
use RuntimeException;

class SyncCourierCitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'couriers:sync-cities {--courier= : Only sync this courier (e.g. Sendit). Defaults to every courier.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the list of covered cities for one or all delivery couriers';

    public function handle(): int
    {
        $slug = $this->option('courier');

        if ($slug) {
            $courier = Courier::tryFrom($slug);

            if (! $courier) {
                $this->error("Unknown courier [{$slug}].");

                return self::FAILURE;
            }

            $couriers = [$courier];
        } else {
            $couriers = Courier::cases();
        }

        $failures = 0;

        foreach ($couriers as $courier) {
            try {
                $count = match ($courier) {
                    Courier::SENDIT => $this->syncSendit(),
                    Courier::OZONEXPRESS => $this->syncOzonExpress(),
                    Courier::COLIIX => $this->syncColiix(),
                    Courier::FORCELOG => $this->syncForceLog(),
                    Courier::AMEEX => $this->syncAmeex(),
                    default => throw new RuntimeException('no cities API is wired up for this courier yet'),
                };

                $this->info("{$courier->value}: synced {$count} cities.");
            } catch (RuntimeException $e) {
                $this->warn("{$courier->value}: skipped ({$e->getMessage()})");
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$courier->value}: failed ({$e->getMessage()})");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fetch every page of Sendit's covered districts and upsert them as cities.
     *
     * Uses the developer public/secret key pair from config('services.sendit')
     * rather than any tenant's stored credentials — this list is a courier-wide
     * dropdown, not scoped to a specific business.
     */
    protected function syncSendit(): int
    {
        $publicKey = (string) config('services.sendit.public_key');
        $secretKey = (string) config('services.sendit.secret_key');

        if (! $publicKey || ! $secretKey) {
            throw new RuntimeException('the SENDIT_PUBLIC_KEY / SENDIT_SECRET_KEY environment variables are not set');
        }

        $courier = DeliveryCourrier::where('slug', Courier::SENDIT->value)->first();

        if (! $courier) {
            throw new RuntimeException('Sendit courier is not seeded yet');
        }

        $login = (new AuthService(''))->login($publicKey, $secretKey);
        $token = $login['data']['token'] ?? throw new RuntimeException('Sendit login did not return a token');

        $service = new DistrictService($token);

        $count = 0;
        $page = 1;

        do {
            $response = $service->getDistricts(['page' => $page]);

            foreach ($response['data'] ?? [] as $district) {
                DeleveryCourrierCity::updateOrCreate(
                    [
                        'courrier_id' => $courier->id,
                        'external_courrier_id' => (string) ($district['id'] ?? ''),
                    ],
                    [
                        'name' => $district['name'] ?? '',
                        'arabic_name' => $district['arabic_name'] ?? null,
                    ],
                );
                $count++;
            }

            $page++;
        } while (! empty($response['next_page_url']));

        return $count;
    }

    /**
     * Fetch OzonExpress's covered cities and upsert them. This is a public,
     * unauthenticated endpoint returning every city in a single response —
     * no credentials or pagination involved.
     */
    protected function syncOzonExpress(): int
    {
        $courier = DeliveryCourrier::where('slug', Courier::OZONEXPRESS->value)->first();

        if (! $courier) {
            throw new RuntimeException('OzonExpress courier is not seeded yet');
        }

        $response = (new OzonExpressClient('', ''))->getCities();

        $count = 0;

        foreach ($response['CITIES'] ?? [] as $city) {
            DeleveryCourrierCity::updateOrCreate(
                [
                    'courrier_id' => $courier->id,
                    'external_courrier_id' => (string) ($city['ID'] ?? ''),
                ],
                [
                    'name' => $city['NAME'] ?? '',
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Fetch ForceLog's covered cities and upsert them.
     *
     * ForceLog's /customer/Cities is authenticated, so unlike OzonExpress's
     * public endpoint this needs a key. It uses the developer key from
     * config('services.forcelog') rather than any tenant's stored
     * credentials — same as syncSendit() — because this list is a
     * courier-wide dropdown, not scoped to a specific business, and the
     * sync must work before any tenant has connected an account.
     *
     * @throws RuntimeException if the courier isn't seeded or the key isn't configured.
     */
    protected function syncForceLog(): int
    {
        $apiKey = (string) config('services.forcelog.api_key');

        if (! $apiKey) {
            throw new RuntimeException('the FORCELOG_API_KEY environment variable is not set');
        }

        $courier = DeliveryCourrier::where('slug', Courier::FORCELOG->value)->first();

        if (! $courier) {
            throw new RuntimeException('ForceLog courier is not seeded yet');
        }

        $cities = ForceLogCityDTO::fromMap(
            (new ForceLogClient($apiKey))->getCities()
        );

        $count = 0;

        foreach ($cities as $city) {
            if ($city->name === '') {
                continue;
            }

            DeleveryCourrierCity::updateOrCreate(
                [
                    'courrier_id' => $courier->id,
                    'external_courrier_id' => $city->id,
                ],
                [
                    'name' => $city->name,
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Ameex publishes no cities API, and its API authenticates before it
     * routes (an unknown path answers identically to a valid one with bad
     * credentials), so no endpoint could be discovered for this. Its
     * coverage list only exists as a static CSV export, same situation as
     * Coliix.
     *
     * The file is semicolon-delimited with a header row, and its first two
     * columns are Ameex's own numeric city id and the city name:
     *
     *   ID;Nom;Frais;Frais EXTRA: Moyen - MED;...
     *   1;Marrakech;35.00;+15;...
     *
     * Only those two columns are imported. The "Frais" columns are NOT a
     * source of delivery cost: this export is scoped to one pickup city
     * (Casablanca) and one account's negotiated rates, so importing it
     * courier-wide would attribute the wrong price to every other tenant
     * and every other pickup city. Ameex quotes no fee through its API at
     * all, so parcels created with this courier carry a null delivery_cost
     * — see AmeexParcelDTO.
     *
     * The export is ISO-8859-1 encoded, not UTF-8 — accented city names
     * ("Aït Attab", "Mellalyène") would be stored as mojibake without the
     * conversion below.
     *
     * @throws RuntimeException if the courier isn't seeded or the CSV is missing.
     */
    protected function syncAmeex(): int
    {
        $courier = DeliveryCourrier::where('slug', Courier::AMEEX->value)->first();

        if (! $courier) {
            throw new RuntimeException('Ameex courier is not seeded yet');
        }

        $path = database_path('data/Ameex-Cities-Pickup-Casablanca.csv');

        if (! is_file($path)) {
            throw new RuntimeException("cities file not found at [{$path}]");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("could not open cities file at [{$path}]");
        }

        $count = 0;
        $isHeader = true;

        while (($row = fgetcsv($handle, separator: ';', escape: '\\')) !== false) {
            // Skip the header rather than assuming a fixed offset, so a
            // re-export that drops or keeps it behaves the same.
            if ($isHeader) {
                $isHeader = false;

                if (! is_numeric(trim((string) ($row[0] ?? '')))) {
                    continue;
                }
            }

            $externalId = trim((string) ($row[0] ?? ''));
            $name = $this->normalizeCsvValue((string) ($row[1] ?? ''));

            if ($externalId === '' || $name === '') {
                continue;
            }

            DeleveryCourrierCity::updateOrCreate(
                [
                    'courrier_id' => $courier->id,
                    'external_courrier_id' => $externalId,
                ],
                [
                    'name' => $name,
                ],
            );
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Decode a value from the Ameex export into UTF-8 and strip the stray
     * quoting/whitespace a few rows carry (e.g. `"Moulay Yaâcoub\t"`).
     */
    private function normalizeCsvValue(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return trim($value, " \t\n\r\0\x0B\"");
    }

    /**
     * Coliix has no cities API — its coverage list only exists as the
     * static "COLIIX - VILLE ET ZONE.csv" export (2 columns: a hub/zone
     * name, and its cities as one-per-line within a single quoted cell).
     * A handful of rows carry no city list at all, just a lone hub name
     * (e.g. "AGADIR", "Hub Dakhla") — that hub name is itself the city in
     * those cases.
     *
     * Coliix's add-parcel API takes a plain city name (no id), so these
     * rows are stored with external_courrier_id left null and upserted by
     * name — unlike Sendit/OzonExpress there's no external id to key on.
     *
     * @throws RuntimeException if the CSV file is missing.
     */
    protected function syncColiix(): int
    {
        $courier = DeliveryCourrier::where('slug', Courier::COLIIX->value)->first();

        if (! $courier) {
            throw new RuntimeException('Coliix courier is not seeded yet');
        }

        $path = database_path('data/COLIIX - VILLE ET ZONE.csv');

        if (! is_file($path)) {
            throw new RuntimeException("cities file not found at [{$path}]");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("could not open cities file at [{$path}]");
        }

        $seen = [];
        $count = 0;

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $hub = trim((string) ($row[0] ?? ''));
            $citiesCell = (string) ($row[1] ?? '');

            $cities = array_filter(array_map('trim', explode("\n", $citiesCell)));

            if (empty($cities) && $hub !== '') {
                $cities = [$hub];
            }

            foreach ($cities as $city) {
                $key = mb_strtolower($city);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                DeleveryCourrierCity::updateOrCreate(
                    [
                        'courrier_id' => $courier->id,
                        'name' => $city,
                    ],
                );
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }
}
