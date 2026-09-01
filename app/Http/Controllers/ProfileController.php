<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedgerEntry;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the authenticated user's profile with their order/commission analytics.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        $orders = Order::where('assigned_agent_id', $user->id);

        $totalAssigned = (clone $orders)->count();
        $confirmedCount = (clone $orders)->whereIn('confirmation_status', [
            'confirmed', 'confirmed_followup', 'submitted_to_courier', 'test_completed',
        ])->count();
        $deliveredCount = (clone $orders)->where('delivery_status', 'delivered')->count();
        $cancelledCount = (clone $orders)->where('confirmation_status', 'cancelled')->count();

        $earned = (float) CommissionLedgerEntry::where('user_id', $user->id)
            ->where('entry_type', 'earned')
            ->sum('amount');
        $reversed = (float) CommissionLedgerEntry::where('user_id', $user->id)
            ->where('entry_type', 'reversal')
            ->sum('amount');

        return Inertia::render('profile', [
            'stats' => [
                'orders_assigned' => $totalAssigned,
                'orders_confirmed' => $confirmedCount,
                'orders_delivered' => $deliveredCount,
                'orders_cancelled' => $cancelledCount,
                'confirmation_rate' => $totalAssigned > 0
                    ? round(($confirmedCount / $totalAssigned) * 100)
                    : 0,
                'commission_earned' => round($earned - $reversed, 2),
            ],
            'ordersByDay' => $this->ordersPerDay($user->id),
        ]);
    }

    /**
     * Count of orders assigned to the user per day over the last 14 days,
     * zero-filled so the chart always has a full, continuous range.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function ordersPerDay(int $userId): array
    {
        $since = Carbon::today()->subDays(13);

        $counts = Order::where('assigned_agent_id', $userId)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, count(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return collect(range(0, 13))
            ->map(function (int $offset) use ($since, $counts) {
                $date = $since->copy()->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'count' => (int) ($counts[$date->toDateString()] ?? 0),
                ];
            })
            ->all();
    }
}
