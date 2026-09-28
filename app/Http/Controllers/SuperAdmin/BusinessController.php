<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreBusinessRequest;
use App\Http\Requests\SuperAdmin\UpdateBusinessRequest;
use App\Http\Requests\SuperAdmin\UpdateBusinessStatusRequest;
use App\Models\Business;
use App\Models\User;
use App\Services\Operations\Performance\PerformanceTargetSeeder;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class BusinessController extends Controller
{
    use BuildsTableQuery;

    /**
     * Columns the businesses table may be sorted by, keyed to their allowed
     * query-string `sort` value.
     */
    private const SORTABLE = ['name', 'slug', 'status', 'users_count', 'created_at'];

    /**
     * Columns matched by the `search` query param.
     */
    private const SEARCHABLE = ['name', 'slug'];

    /**
     * Display every business on the platform, paginated and filtered
     * server-side so the panel stays fast as tenants accumulate.
     */
    public function index(Request $request): Response
    {
        $query = Business::query()
            ->withCount('users')
            ->when(
                BusinessStatus::tryFrom($request->string('status')->toString()),
                fn ($query, BusinessStatus $status) => $query->where('status', $status),
            );

        $query = $this->applySearch($query, $request, self::SEARCHABLE);
        $query = $this->applySort($query, $request, self::SORTABLE);

        return Inertia::render('super-admin/businesses/index', [
            'businesses' => $query->paginate($this->resolvePerPage($request))->withQueryString(),
            'filters' => $request->only(['search', 'status', 'sort', 'direction', 'per_page']),
        ]);
    }

    /**
     * Show the form for onboarding a new business and its admin user.
     */
    public function create(): Response
    {
        return Inertia::render('super-admin/businesses/create');
    }

    /**
     * Store a newly onboarded business and its first admin user.
     */
    public function store(StoreBusinessRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['business_name'],
                'slug' => $this->uniqueSlug($data['business_name']),
                'status' => $data['business_status'],
            ]);

            $admin = new User([
                'business_id' => $business->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'phone' => PhoneNumber::format($data['admin_phone'] ?? null),
                'role' => UserRole::ADMIN,
                'status' => UserStatus::ACTIVE,
            ]);

            $admin->password = Hash::make($data['admin_password']);
            $admin->save();

            // Business-wide performance targets, so an agent created
            // without explicit targets is still measured against something.
            app(PerformanceTargetSeeder::class)->seed($business);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Business onboarded.')]);

        return to_route('super-admin.businesses.index');
    }

    /**
     * Platform-level read-only view of a single tenant: who works there,
     * what they've connected, and where their billing stands.
     */
    public function show(Business $business): Response
    {
        $business->loadCount(['users', 'stores', 'orders', 'products']);

        return Inertia::render('super-admin/businesses/show', [
            'business' => $business,
            'users' => $business->users()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at']),
            'stores' => $business->stores()
                ->with('platform:id,name,slug')
                ->orderBy('name')
                ->get(['id', 'platform_id', 'name', 'domain', 'connection_status', 'last_synced_at']),
        ]);
    }

    /**
     * Show the form for editing a business's identity.
     */
    public function edit(Business $business): Response
    {
        return Inertia::render('super-admin/businesses/edit', [
            'business' => $business->only(['id', 'name', 'slug', 'status']),
        ]);
    }

    /**
     * Update a business's identity. Status is handled by updateStatus.
     */
    public function update(UpdateBusinessRequest $request, Business $business): RedirectResponse
    {
        $business->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Business updated.')]);

        return to_route('super-admin.businesses.show', $business);
    }

    /**
     * Move a business between lifecycle states. This is the one switch that
     * decides whether a whole business can work today.
     *
     * Suspending or cancelling takes effect immediately, not at the next
     * login: EnsureAccountStillUsable re-checks the block reason on every
     * web request, and the tenant's mobile API tokens are deleted here so a
     * fulfilment agent's phone stops working in the same moment.
     */
    public function updateStatus(UpdateBusinessStatusRequest $request, Business $business): RedirectResponse
    {
        $status = $request->status();

        DB::transaction(function () use ($business, $status) {
            $business->update(['status' => $status]);

            if ($status !== BusinessStatus::ACTIVE) {
                $this->revokeMobileTokens($business);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($status) {
                BusinessStatus::ACTIVE => __('Business reactivated.'),
                BusinessStatus::SUSPENDED => __('Business suspended. Everyone at this business has been signed out.'),
                BusinessStatus::CANCELLED => __('Business cancelled. Everyone at this business has been signed out.'),
            },
        ]);

        return back();
    }

    /**
     * Delete every mobile API token belonging to a business's users, so a
     * signed-in phone stops working the moment the business is suspended
     * rather than holding a valid token until it expires.
     */
    protected function revokeMobileTokens(Business $business): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $business->users()->select('id'))
            ->delete();
    }

    /**
     * Generate a slug from the business name, disambiguated with a numeric
     * suffix on collision since the column carries a unique constraint.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 1;

        while (Business::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
