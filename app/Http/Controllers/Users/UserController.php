<?php

namespace App\Http\Controllers\Users;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\SyncsAgentCompensation;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Order;
use App\Models\PerformanceTarget;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class UserController extends Controller
{
    use SyncsAgentCompensation;

    /**
     * Display the list of users for the current business.
     */
    public function index(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $users = User::query()
            ->where('business_id', $businessId)
            ->whereNotIn('role', [UserRole::SUPER_ADMIN, UserRole::ADMIN])
            ->with(['commissionRules.store', 'commissionRules.product', 'performanceTargets', 'agentScopes.store', 'agentScopes.product'])
            ->orderBy('name')
            ->get();

        return Inertia::render('users/index', [
            'users' => $users,
            'workload' => $this->workload($businessId, $users),
        ]);
    }

    /**
     * What is on each agent's desk right now, keyed by user id. Two
     * grouped queries for the whole team, never one per agent, both
     * answered from covering indexes on orders.
     *
     * - Confirmation agents: their open orders (no outcome yet), split
     *   into still to call and being followed up — the same statuses the
     *   dashboard counts as in progress. Test orders are left out.
     * - Fulfilment agents: parcels to prepare and returns to receive in the
     *   stores they are granted (all stores when they have no grant),
     *   counted exactly like their own scan app's home screen.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, int>>
     */
    private function workload(int $businessId, Collection $users): array
    {
        $toCall = array_map(fn (OrderConfirmationStatus $status) => $status->value, OrderConfirmationStatus::toCall());

        $open = Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->whereIn('confirmation_status', OrderConfirmationStatus::awaitingOutcome())
            ->whereNotNull('assigned_agent_id')
            ->groupBy('assigned_agent_id')
            ->toBase()
            ->selectRaw(
                'assigned_agent_id, count(*) as total, sum(case when confirmation_status in (?, ?) then 1 else 0 end) as to_call',
                $toCall,
            )
            ->get()
            ->keyBy('assigned_agent_id');

        $parcels = $users->contains('role', UserRole::FULFILMENT_AGENT)
            ? Order::where('business_id', $businessId)
                ->whereIn('delivery_status', [OrderDeliveryStatus::AWAITING_PICKUP, OrderDeliveryStatus::RETURNED_IN_TRANSIT])
                ->groupBy('store_id', 'delivery_status')
                ->toBase()
                ->selectRaw('store_id, delivery_status, count(*) as total')
                ->get()
            : collect();

        // Delivery guys: parcels to collect / on the road, and the COD cash
        // they are holding (delivered, not yet handed over).
        $delivery = $users->contains('role', UserRole::DELIVERY_GUY)
            ? Order::where('business_id', $businessId)
                ->whereNotNull('delivery_guy_id')
                ->where(fn ($query) => $query
                    ->whereIn('delivery_status', [
                        OrderDeliveryStatus::READY_FOR_PICKUP,
                        OrderDeliveryStatus::OUT_FOR_DELIVERY,
                        OrderDeliveryStatus::POSTPONED,
                        OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
                    ])
                    ->orWhere(fn ($query) => $query->where('delivery_status', OrderDeliveryStatus::DELIVERED)->whereNull('delivery_cash_settlement_id')))
                ->toBase()
                ->selectRaw("delivery_guy_id,
                    sum(case when delivery_status = 'ready_for_pickup' then 1 else 0 end) as to_collect,
                    sum(case when delivery_status in ('out_for_delivery', 'postponed', 'delivery_attempt_failed') then 1 else 0 end) as on_road,
                    coalesce(sum(case when delivery_status = 'delivered' then total_amount else 0 end), 0) as cash_owed")
                ->groupBy('delivery_guy_id')
                ->get()
                ->keyBy('delivery_guy_id')
            : collect();

        $workload = [];

        foreach ($users as $user) {
            if ($user->role === UserRole::CONFIRMATION_AGENT) {
                $row = $open->get($user->id);
                $total = (int) ($row->total ?? 0);
                $calls = (int) ($row->to_call ?? 0);

                $workload[$user->id] = ['open' => $total, 'toCall' => $calls, 'followUp' => $total - $calls];
            } elseif ($user->role === UserRole::FULFILMENT_AGENT) {
                // Granted stores come from the scopes already eager loaded
                // for the Scope column; none means the whole business.
                $storeIds = $user->agentScopes->pluck('store_id')->filter()->values();
                $mine = $storeIds->isEmpty()
                    ? $parcels
                    : $parcels->filter(fn ($row) => $storeIds->contains((int) $row->store_id));

                $count = fn (OrderDeliveryStatus $status) => (int) $mine
                    ->where('delivery_status', $status->value)
                    ->sum('total');

                $workload[$user->id] = [
                    'toPrepare' => $count(OrderDeliveryStatus::AWAITING_PICKUP),
                    'returns' => $count(OrderDeliveryStatus::RETURNED_IN_TRANSIT),
                ];
            } elseif ($user->role === UserRole::DELIVERY_GUY) {
                $row = $delivery->get($user->id);

                $workload[$user->id] = [
                    'toCollect' => (int) ($row->to_collect ?? 0),
                    'onRoad' => (int) ($row->on_road ?? 0),
                    'cashOwed' => round((float) ($row->cash_owed ?? 0), 2),
                ];
            }
        }

        return $workload;
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request): Response
    {
        $businessId = $request->user()->business_id;
        $role = $request->query('role', 'confirmation_agent');

        if (! in_array($role, ['confirmation_agent', 'fulfilment_agent', 'delivery_guy', 'creatives_editor'])) {
            $role = 'confirmation_agent';
        }

        return Inertia::render('users/create', [
            'role' => $role,
            'performanceDefaults' => $this->defaultPerformanceTargets($businessId),
            'stores' => Store::where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'avatarOptions' => collect(glob(public_path('assets/images/avatars/*.png')) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort(SORT_NATURAL)
                ->values(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $data) {
            $user = new User([
                'business_id' => $request->user()->business_id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => PhoneNumber::format($data['phone'] ?? null),
                'role' => $data['role'],
                'status' => $data['status'],
            ]);

            $user->password = Hash::make($data['password']);

            if ($request->hasFile('avatar')) {
                $path = $request->file('avatar')->store('avatars', 'public');

                if ($path === false) {
                    throw new RuntimeException('Failed to store the uploaded avatar.');
                }

                $user->avatar = $path;
            } elseif (! empty($data['avatar_preset'])) {
                $user->avatar = 'assets/images/avatars/'.$data['avatar_preset'];
            }

            $user->save();

            $this->syncAgentCompensation($user, $data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('users.index');
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(Request $request, User $user): Response
    {
        abort_unless($user->business_id === $request->user()->business_id, 403);

        $user->load(['commissionRules.store', 'commissionRules.product', 'performanceTargets', 'agentScopes.store', 'agentScopes.product']);

        $businessId = $request->user()->business_id;

        return Inertia::render('users/edit', [
            'user' => $user,
            'performanceDefaults' => $this->defaultPerformanceTargets($businessId),
            'stores' => Store::where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'avatarOptions' => collect(glob(public_path('assets/images/avatars/*.png')) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort(SORT_NATURAL)
                ->values(),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $request->user()->business_id, 403);

        $data = $request->validated();

        DB::transaction(function () use ($request, $user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => PhoneNumber::format($data['phone'] ?? null),
                'role' => $data['role'],
                'status' => $data['status'],
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            if ($request->hasFile('avatar')) {
                $this->deleteUploadedAvatar($user);

                $path = $request->file('avatar')->store('avatars', 'public');

                if ($path === false) {
                    throw new RuntimeException('Failed to store the uploaded avatar.');
                }

                $user->avatar = $path;
            } elseif (! empty($data['avatar_preset'])) {
                $this->deleteUploadedAvatar($user);

                $user->avatar = 'assets/images/avatars/'.$data['avatar_preset'];
            } elseif ($request->boolean('remove_avatar') && $user->avatar) {
                $this->deleteUploadedAvatar($user);

                $user->avatar = null;
            }

            $user->save();

            $this->syncAgentCompensation($user, $data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $request->user()->business_id, 403);
        abort_if($user->id === $request->user()->id, 403, __('You cannot delete your own account.'));

        $this->deleteUploadedAvatar($user);

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('users.index');
    }

    /**
     * Delete the user's uploaded avatar file, if any. Preset avatars live in
     * public/assets and are shared between users, so they are never deleted.
     * Uses the raw column value — the accessor prepends the public URL prefix.
     */
    protected function deleteUploadedAvatar(User $user): void
    {
        $path = $user->getRawOriginal('avatar');

        if ($path && str_starts_with($path, 'avatars/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The business-wide target values an agent falls back to when they have
     * no override — what the agent form shows as "Default (…)".
     *
     * Read from the business's own PerformanceTarget rows so the label
     * states the value the evaluator will actually use, falling back to
     * config only for businesses created before those rows were seeded.
     *
     * @return array<string, float|string>
     */
    private function defaultPerformanceTargets(?int $businessId): array
    {
        $rows = PerformanceTarget::where('business_id', $businessId)
            ->whereNull('user_id')
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (PerformanceTarget $target) => $target->metric->value);

        $defaults = [];

        foreach (config('performance.defaults') as $metric => $fallback) {
            $row = $rows->get($metric);

            $defaults[$metric] = $row instanceof PerformanceTarget
                ? (float) $row->target_percentage
                : (float) $fallback;
        }

        // The window an agent's own target inherits when the admin doesn't
        // pick one. Taken from the confirmation-rate row as the business's
        // representative setting.
        $representative = $rows->get('confirmation_rate');

        $defaults['period'] = $representative instanceof PerformanceTarget
            ? $representative->period->value
            : (string) config('performance.default_period');

        return $defaults;
    }
}
