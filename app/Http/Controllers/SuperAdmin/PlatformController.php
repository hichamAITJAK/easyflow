<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Concerns\StoresCatalogLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdatePlatformRequest;
use App\Models\EcommercePlatform;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presentation-level control over the e-commerce platform catalog.
 *
 * Deliberately edit-only: a platform's `slug` is bound to the EcomPlatform
 * enum and resolved by roughly a dozen integration call sites
 * (`where('slug', EcomPlatform::SHOPIFY->value)->firstOrFail()`), each
 * backed by its own connection service. Creating a row from here would
 * produce a platform with no service behind it, and editing a slug would
 * break every store already connected through it — so neither is offered.
 * Adding a platform stays a code change.
 */
class PlatformController extends Controller
{
    use StoresCatalogLogo;

    /** Where uploaded platform logos live on the public disk. */
    private const LOGO_DIRECTORY = 'platform-logos';

    public function index(): Response
    {
        $platforms = EcommercePlatform::query()
            ->withCount('stores')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'logo_url', 'created_at']);

        return Inertia::render('super-admin/platforms/index', [
            'platforms' => $platforms,
        ]);
    }

    public function edit(EcommercePlatform $platform): Response
    {
        return Inertia::render('super-admin/platforms/edit', [
            'platform' => $platform->only(['id', 'name', 'slug', 'description', 'logo_url']),
        ]);
    }

    /**
     * Update the copy and logo shown to tenants. `slug` is not accepted —
     * see the class docblock.
     */
    public function update(UpdatePlatformRequest $request, EcommercePlatform $platform): RedirectResponse
    {
        $validated = $request->validated();

        [$logoChanged, $logoPath] = $this->resolveLogo($request, $platform, 'logo_url', self::LOGO_DIRECTORY);

        $platform->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        if ($logoChanged) {
            $platform->logo_url = $logoPath;
        }

        $platform->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Platform updated.')]);

        return to_route('super-admin.platforms.index');
    }
}
