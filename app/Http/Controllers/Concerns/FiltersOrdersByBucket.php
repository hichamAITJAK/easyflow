<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\OrderConfirmationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Groups confirmation_status into the same four buckets the mobile
 * Confirmation Agent app filters its queue by (see LeadsController) — a
 * confirmation agent thinks of their queue as "new / to follow up /
 * confirmed / shipped", not as twelve individual status values, so the web
 * call-center queue (orders/queue.tsx) offers the same buckets instead of
 * the admin table's raw per-status filter.
 */
trait FiltersOrdersByBucket
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyOrderBucket(Builder $query, string $bucket): Builder
    {
        return match ($bucket) {
            'new' => $query->whereIn('confirmation_status', [
                OrderConfirmationStatus::NEW->value,
                OrderConfirmationStatus::ASSIGNED->value,
            ]),
            'follow_up' => $query->whereIn('confirmation_status', [
                OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value,
                OrderConfirmationStatus::CALLBACK->value,
                OrderConfirmationStatus::VOICEMAIL->value,
                OrderConfirmationStatus::NO_ANSWER->value,
                OrderConfirmationStatus::BUSY->value,
                OrderConfirmationStatus::WHATSAPP_SENT->value,
            ]),
            'confirmed' => $query->where('confirmation_status', OrderConfirmationStatus::CONFIRMED->value),
            'shipped' => $query->where('confirmation_status', OrderConfirmationStatus::SUBMITTED_TO_COURIER->value),
            default => $query,
        };
    }

    /**
     * Per-bucket counts for the queue's filter chip badges, computed in one
     * pass so the chip row never issues four separate count queries.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{all: int, new: int, follow_up: int, confirmed: int, shipped: int}
     */
    private function orderBucketCounts(Builder $query): array
    {
        $statusCounts = (clone $query)
            ->selectRaw('confirmation_status, count(*) as aggregate')
            ->groupBy('confirmation_status')
            ->pluck('aggregate', 'confirmation_status');

        $followUpStatuses = [
            OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value,
            OrderConfirmationStatus::CALLBACK->value,
            OrderConfirmationStatus::VOICEMAIL->value,
            OrderConfirmationStatus::NO_ANSWER->value,
            OrderConfirmationStatus::BUSY->value,
            OrderConfirmationStatus::WHATSAPP_SENT->value,
        ];

        return [
            'all' => (int) $statusCounts->sum(),
            'new' => (int) ($statusCounts[OrderConfirmationStatus::NEW->value] ?? 0)
                + (int) ($statusCounts[OrderConfirmationStatus::ASSIGNED->value] ?? 0),
            'follow_up' => (int) collect($followUpStatuses)->sum(fn ($status) => $statusCounts[$status] ?? 0),
            'confirmed' => (int) ($statusCounts[OrderConfirmationStatus::CONFIRMED->value] ?? 0),
            'shipped' => (int) ($statusCounts[OrderConfirmationStatus::SUBMITTED_TO_COURIER->value] ?? 0),
        ];
    }
}
