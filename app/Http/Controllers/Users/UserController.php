<?php

namespace App\Http\Controllers\Users;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\SyncsAgentCompensation;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\PerformanceTarget;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request): Response
    {
        $businessId = $request->user()->business_id;
        $role = $request->query('role', 'confirmation_agent');

        if (! in_array($role, ['confirmation_agent', 'fulfilment_agent', 'creatives_editor'])) {
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
