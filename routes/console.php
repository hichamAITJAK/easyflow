<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drains the queue and exits instead of running as a daemon, so it's safe
// on hosts (e.g. shared hosting) where a long-lived `queue:work` process
// isn't allowed — cron just needs to hit `schedule:run` every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Most couriers give no webhook for delivery status changes, so this is
// what keeps orders.delivery_status from going stale once a parcel ships.
Schedule::command('orders:sync-delivery-statuses')
    ->hourly()
    ->withoutOverlapping();

// UC-22: once a day is enough for a rolling confirmation/delivery-success
// check — these are trend metrics over daily/weekly/monthly windows, not
// something that needs intraday freshness.
Schedule::command('agents:check-performance')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Pays out bonuses on targets that carry one. Runs daily rather than at
// period end so an agent who meets a monthly target early is paid then;
// every later run in the same window is a no-op (unique index on the
// agent/target/period, see PerformanceBonusAwarder). Just after the
// warning check, so both read the same day's stats.
Schedule::command('agents:award-bonuses')
    ->dailyAt('08:15')
    ->withoutOverlapping();

// PRD section 5: the pre-computed dashboard stats are updated near-real-
// time by queued listeners; this nightly full recalculation is the
// correctness safety net that heals any drift from lost queue jobs.
// 03:00 — quietest window for the COD calling business.
Schedule::command('stats:rebuild')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Expires past-due trials/subscriptions and fires renewal reminders at the
// configured thresholds. Early morning so the block/banner state is right
// before merchants start their day.
Schedule::command('subscriptions:check')
    ->dailyAt('06:00')
    ->withoutOverlapping();
