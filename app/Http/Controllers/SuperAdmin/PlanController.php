<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StorePlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full control over the subscription plan catalog.
 *
 * Unlike platforms and couriers, a plan is ordinary data: nothing resolves
 * it through an enum or a per-plan service class, so it can be created and
 * edited freely. The one constraint is history — a plan that has ever been
 * sold can't be deleted, because its subscriptions reference it.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::query()
            ->withCount('subscriptions')
            ->orderBy('price')
            ->get();

        return Inertia::render('super-admin/plans/index', [
            'plans' => $plans,
            'limitKeys' => StorePlanRequest::LIMIT_KEYS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('super-admin/plans/create', [
            'limitKeys' => StorePlanRequest::LIMIT_KEYS,
            'defaultCurrency' => 'MAD',
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        Plan::create($request->planAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan created.')]);

        return to_route('super-admin.plans.index');
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('super-admin/plans/edit', [
            'plan' => $plan->only(['id', 'name', 'slug', 'price', 'currency', 'duration_days', 'limits', 'is_active']),
            'limitKeys' => StorePlanRequest::LIMIT_KEYS,
            'subscriptionsCount' => $plan->subscriptions()->count(),
        ]);
    }

    /**
     * Editing a plan changes what future subscribers get, not what current
     * ones already bought: SubscriptionService snapshots `limits` onto the
     * subscription at activation, so live cycles keep the ceilings they
     * were sold.
     */
    public function update(StorePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->planAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan updated.')]);

        return to_route('super-admin.plans.index');
    }

    /**
     * Delete a plan that was never sold. Anything with subscription history
     * must be deactivated instead, so the record those cycles point at
     * stays intact.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->exists()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This plan has been subscribed to, so it can only be deactivated.'),
            ]);

            return back();
        }

        $plan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan deleted.')]);

        return to_route('super-admin.plans.index');
    }

    /**
     * Toggle whether the plan is offered to tenants. Deactivating hides it
     * from the subscription page without touching anyone already on it.
     */
    public function toggle(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $plan->is_active ? __('Plan activated.') : __('Plan deactivated.'),
        ]);

        return back();
    }
}
