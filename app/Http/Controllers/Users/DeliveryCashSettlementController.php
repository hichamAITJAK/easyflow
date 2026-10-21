<?php

namespace App\Http\Controllers\Users;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Operations\Settlements\DeliveryCashSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The admin records that a delivery guy handed over the COD cash he was
 * holding: every delivered parcel of his with no settlement yet is tied
 * to one new settlement row, and his "cash owed" drops to zero.
 */
class DeliveryCashSettlementController extends Controller
{
    public function __construct(private readonly DeliveryCashSettlementService $settlements) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $request->user()->business_id, 403);
        abort_unless($user->role === UserRole::DELIVERY_GUY, 404);

        $data = $request->validate([
            'received_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $settlement = $this->settlements->settle(
            $user,
            $request->user(),
            isset($data['received_amount']) ? (float) $data['received_amount'] : null,
            $data['note'] ?? null,
        );

        Inertia::flash('toast', $settlement === null
            ? ['type' => 'info', 'message' => __(':name has no cash to hand over.', ['name' => $user->name])]
            : ($settlement->status === 'reconciled'
                ? ['type' => 'success', 'message' => __('Cash settled: :amount MAD for :count parcels.', [
                    'amount' => number_format((float) $settlement->amount, 2, '.', ','),
                    'count' => $settlement->parcels_count,
                ])]
                : ['type' => 'error', 'message' => __('Amounts do not match (:difference MAD). The settlement is marked as disputed.', [
                    'difference' => number_format((float) $settlement->difference_amount, 2),
                ])]));

        return back(fallback: route('users.index'));
    }
}
