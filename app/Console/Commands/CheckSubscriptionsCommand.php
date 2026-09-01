<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class CheckSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire past-due subscriptions and fire renewal reminders for businesses whose subscription comes to an end';

    public function handle(SubscriptionService $subscriptions): int
    {
        $subscriptions->runDailyCheck();

        $this->info('Subscription check complete.');

        return self::SUCCESS;
    }
}
