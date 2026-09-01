<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\Courier;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Concerns\StoresCatalogLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateCourierRequest;
use App\Models\DeliveryCourrier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presentation-level control over the delivery courier catalog, plus the
 * per-courier city list.
 *
 * Edit-only for the same reason as PlatformController: a courier's `slug`
 * keys the Courier enum and each courier's own operation service, so rows
 * can't be created or re-slugged from here.
 */
class CourierController extends Controller
{
    use BuildsTableQuery;
    use StoresCatalogLogo;

    /** Where uploaded courier logos live on the public disk. */
    private const LOGO_DIRECTORY = 'courier-logos';

    /**
     * Couriers whose cities can be pulled from an API. The sync command
     * throws "no cities API is wired up" for anything else, so the UI only
     * offers the button where it will actually do something.
     *
     * Kept in step with SyncCourierCitiesCommand::handle()'s match arms.
     *
     * @var list<string>
     */
    private const SYNCABLE = [
        Courier::SENDIT->value,
        Courier::OZONEXPRESS->value,
        Courier::COLIIX->value,
        Courier::FORCELOG->value,
        Courier::AMEEX->value,
    ];

    public function index(): Response
    {
        $couriers = DeliveryCourrier::query()
            ->withCount(['cities', 'deliveryAccounts'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'logo'])
            ->map(fn (DeliveryCourrier $courier): array => [
                ...$courier->only(['id', 'name', 'slug', 'description', 'logo']),
                'cities_count' => $courier->cities_count,
                'delivery_accounts_count' => $courier->delivery_accounts_count,
                'syncable' => in_array($courier->slug, self::SYNCABLE, true),
            ]);

        return Inertia::render('super-admin/couriers/index', [
            'couriers' => $couriers,
        ]);
    }

    public function edit(DeliveryCourrier $courier): Response
    {
        return Inertia::render('super-admin/couriers/edit', [
            'courier' => $courier->only(['id', 'name', 'slug', 'description', 'logo']),
        ]);
    }

    public function update(UpdateCourierRequest $request, DeliveryCourrier $courier): RedirectResponse
    {
        $validated = $request->validated();

        [$logoChanged, $logoPath] = $this->resolveLogo($request, $courier, 'logo', self::LOGO_DIRECTORY);

        $courier->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        if ($logoChanged) {
            $courier->logo = $logoPath;
        }

        $courier->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Courier updated.')]);

        return to_route('super-admin.couriers.index');
    }

    /**
     * The courier's covered cities — the dropdown a tenant picks from when
     * creating a parcel, so it's worth being able to inspect and search.
     */
    public function cities(Request $request, DeliveryCourrier $courier): Response
    {
        $query = $courier->cities()->getQuery();
        $query = $this->applySearch($query, $request, ['name', 'arabic_name', 'external_courrier_id']);
        $query = $this->applySort($query, $request, ['name', 'external_courrier_id', 'updated_at'], default: 'name', defaultDirection: 'asc');

        return Inertia::render('super-admin/couriers/cities', [
            'courier' => [
                ...$courier->only(['id', 'name', 'slug']),
                'syncable' => in_array($courier->slug, self::SYNCABLE, true),
            ],
            'cities' => $query->paginate($this->resolvePerPage($request))->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    /**
     * Pull this courier's city list from its API.
     *
     * Runs inline rather than queued: the super admin clicked it and wants
     * the refreshed list on the next render. The command talks to one
     * courier here, so this is a single upstream call, not a fan-out.
     */
    public function syncCities(DeliveryCourrier $courier): RedirectResponse
    {
        abort_unless(in_array($courier->slug, self::SYNCABLE, true), 422);

        $exitCode = Artisan::call('couriers:sync-cities', ['--courier' => $courier->slug]);

        // The command reports per-courier problems on its own output rather
        // than throwing, so the exit code is what distinguishes a real
        // failure from a clean run.
        Inertia::flash('toast', $exitCode === 0
            ? ['type' => 'success', 'message' => __(':courier cities synced.', ['courier' => $courier->name])]
            : ['type' => 'error', 'message' => __('Sync failed. Check the courier API credentials.')]);

        return back();
    }
}
