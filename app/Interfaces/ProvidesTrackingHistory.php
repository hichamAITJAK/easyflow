<?php

namespace App\Interfaces;

use Illuminate\Support\Carbon;

/**
 * A courier whose tracking call returns the parcel's whole journey, not
 * just its latest status. The status sync saves every step and reads the
 * current status from the same answer, so it costs one request per parcel.
 */
interface ProvidesTrackingHistory
{
    /**
     * The parcel's steps, oldest first.
     *
     * @return list<array{status: string, happened_at: Carbon|null, payment_state: string|null}>
     */
    public function getTrackingHistory(string $trackingNumber): array;
}
