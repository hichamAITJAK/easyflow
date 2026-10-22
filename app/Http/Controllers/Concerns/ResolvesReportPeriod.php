<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The period presets shared by the finance screens: a named window
 * (today, 7d, 30d, this_month, last_month, 90d) or an explicit
 * date_from / date_to pair when the period is `custom`.
 *
 * A custom range that is unparseable, inverted, or missing an endpoint
 * falls back to the default preset rather than erroring: the window is a
 * view setting, and a bad link should still render a page. Ranges are
 * clamped to MAX_REPORT_RANGE_DAYS and never reach past today.
 */
trait ResolvesReportPeriod
{
    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveReportPeriod(string $period, Request $request, int $defaultDays = 30, int $maxDays = 366): array
    {
        $today = Carbon::today();

        if ($period === 'custom') {
            $custom = $this->resolveReportCustomRange($request, $today, $maxDays);

            if ($custom !== null) {
                return $custom;
            }
        }

        return match ($period) {
            'today' => [$today->copy(), $today],
            '7d' => [$today->copy()->subDays(6), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            '90d' => [$today->copy()->subDays(89), $today],
            default => [$today->copy()->subDays($defaultDays - 1), $today],
        };
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function resolveReportCustomRange(Request $request, Carbon $today, int $maxDays): ?array
    {
        $from = $this->parseReportDate($request->string('date_from')->toString());
        $to = $this->parseReportDate($request->string('date_to')->toString());

        if ($from === null || $to === null) {
            return null;
        }

        // A backwards range is a mis-built link, not a request for zero
        // rows — swapping is what the user visibly meant.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($to->greaterThan($today)) {
            $to = $today->copy();
        }

        if ($from->greaterThan($to)) {
            return null;
        }

        if ($from->diffInDays($to) + 1 > $maxDays) {
            $from = $to->copy()->subDays($maxDays - 1);
        }

        return [$from, $to];
    }

    private function parseReportDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
