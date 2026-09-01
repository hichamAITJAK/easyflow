<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BusinessPerformanceUpdateRequest;
use App\Http\Requests\Settings\BusinessProfileUpdateRequest;
use App\Models\Business;
use App\Models\PerformanceTarget;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business-level settings — the values that apply across the whole
 * business rather than to one user.
 *
 * Currently the business-wide performance targets (PerformanceTarget rows
 * with user_id null). These are what an agent is measured against when
 * they have no target of their own, so without this page they were seeded
 * once at signup and then unreachable: an admin could override a single
 * agent but never change what "default" meant.
 */
class BusinessController extends Controller
{
    /**
     * Metrics editable here — every metric the evaluator understands.
     *
     * @var array<int, string>
     */
    private const EDITABLE_METRICS = ['confirmation_rate', 'delivery_success_rate'];

    public function edit(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $rows = PerformanceTarget::where('business_id', $businessId)
            ->whereNull('user_id')
            ->get()
            ->keyBy(fn (PerformanceTarget $target) => $target->metric->value);

        $targets = [];

        foreach (self::EDITABLE_METRICS as $metric) {
            $row = $rows->get($metric);

            // Falls back to config for a business created before targets
            // were seeded, so the form always shows the value actually in
            // effect rather than an empty field.
            $targets[$metric] = $row instanceof PerformanceTarget
                ? (float) $row->target_percentage
                : (float) config("performance.defaults.{$metric}");

            $targets[$metric.'_period'] = $row instanceof PerformanceTarget
                ? $row->period->value
                : (string) config('performance.default_period');

            $targets[$metric.'_bonus'] = $row instanceof PerformanceTarget && $row->bonus_amount !== null
                ? (float) $row->bonus_amount
                : null;
        }

        $first = $rows->get('confirmation_rate');

        return Inertia::render('settings/business', [
            'business' => $request->user()->business->only([
                'name', 'legal_name', 'logo', 'ice', 'rc', 'if_number',
                'phone', 'email', 'address', 'city',
            ]),
            'targets' => $targets,
            'minOrdersForEvaluation' => $first instanceof PerformanceTarget
                ? $first->min_orders_for_evaluation
                : (int) config('performance.min_orders_for_evaluation'),
        ]);
    }

    /**
     * Update the business's own identity details. These print on commission
     * invoices, which is why they live here rather than being super-admin
     * data: the business owns its own letterhead.
     */
    public function updateProfile(BusinessProfileUpdateRequest $request): RedirectResponse
    {
        $business = $request->user()->business;
        $data = $request->validated();

        $business->fill([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'ice' => $data['ice'] ?? null,
            'rc' => $data['rc'] ?? null,
            'if_number' => $data['if_number'] ?? null,
            'phone' => PhoneNumber::format($data['phone'] ?? null),
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $this->deleteLogo($business);
            $business->logo = $request->file('logo')->store('business-logos', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo($business);
            $business->logo = null;
        }

        $business->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Business details updated.'),
        ]);

        return back();
    }

    /**
     * Delete the stored logo file. Reads the raw column rather than the
     * accessor, which returns a public URL ("/storage/...") that no disk
     * path matches.
     */
    private function deleteLogo(Business $business): void
    {
        $path = $business->getRawOriginal('logo');

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Update the business-wide targets. Takes effect immediately for every
     * agent without an override — the evaluator, the nightly warning
     * command and both dashboards all read these rows directly.
     */
    public function update(BusinessPerformanceUpdateRequest $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        $data = $request->validated();

        foreach (self::EDITABLE_METRICS as $metric) {
            PerformanceTarget::updateOrCreate(
                // updateOrCreate rather than update: a business created
                // before target seeding existed has no row to update.
                ['business_id' => $businessId, 'user_id' => null, 'metric' => $metric],
                [
                    'target_percentage' => $data['targets'][$metric],
                    'min_orders_for_evaluation' => $data['min_orders_for_evaluation'],
                    'period' => $data['targets'][$metric.'_period'],
                    // Blank clears the bonus rather than storing 0, which
                    // would read as "meeting this pays nothing".
                    'bonus_amount' => ($data['targets'][$metric.'_bonus'] ?? null) !== null
                        && $data['targets'][$metric.'_bonus'] !== ''
                        ? $data['targets'][$metric.'_bonus']
                        : null,
                    'is_active' => true,
                ],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Business performance targets updated.'),
        ]);

        return back();
    }
}
