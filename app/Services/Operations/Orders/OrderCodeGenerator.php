<?php

namespace App\Services\Operations\Orders;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Generates a unique, human-readable business-facing reference for an
 * order, e.g. "ORD-7K9XPQ".
 *
 * Checked against the orders table and retried on collision —
 * astronomically unlikely at this alphabet/length, but "unique" means
 * enforced, not just assumed.
 */
class OrderCodeGenerator
{
    /**
     * Characters safe for humans to read aloud/type back: no 0/O or 1/I/L.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const MAX_ATTEMPTS = 10;

    /**
     * Generate a unique order reference, e.g. "ORD-7K9XPQ".
     */
    public function reference(): string
    {
        return $this->unique(
            column: 'reference',
            make: fn () => 'ORD-'.$this->randomCode(6),
        );
    }

    /**
     * Keep generating with $make until a value doesn't already exist in
     * orders.$column (including soft-deleted rows, since the column stays
     * unique-indexed regardless of trashed state).
     */
    /**
     * A tracking number for a parcel our own delivery guy carries, e.g.
     * LOC-AGA-7K2M9P: the city's first letters make the label readable at
     * a glance in the warehouse.
     */
    public function localTracking(?string $city): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', Str::ascii((string) $city)) ?: 'LOC', 0, 3));

        return $this->unique(
            column: 'courier_tracking_number',
            make: fn () => "LOC-{$prefix}-".$this->randomCode(6),
        );
    }

    private function unique(string $column, \Closure $make): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $make();

            if (! Order::withTrashed()->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Could not generate a unique orders.{$column} after ".self::MAX_ATTEMPTS.' attempts.');
    }

    private function randomCode(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        return collect(range(1, $length))
            ->map(fn () => $alphabet[random_int(0, $max)])
            ->implode('');
    }
}
