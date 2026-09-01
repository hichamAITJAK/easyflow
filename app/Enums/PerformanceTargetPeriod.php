<?php

namespace App\Enums;

enum PerformanceTargetPeriod: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    /**
     * The trailing window (in days, inclusive of today) this period
     * evaluates a target over — UC-22's rolling rate is summed across
     * DailyStatsSummary rows in this window.
     */
    public function days(): int
    {
        return match ($this) {
            self::DAILY => 1,
            self::WEEKLY => 7,
            self::MONTHLY => 30,
        };
    }
}
