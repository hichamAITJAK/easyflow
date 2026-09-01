<?php

use App\Enums\UserRole;
use App\Jobs\SyncStoreProductsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Insert a row shaped the way Laravel's database queue driver writes one.
 */
function queueJob(string $queue = 'default', int $availableSecondsAgo = 0, bool $reserved = false): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\ProcessShopifyOrderWebhookJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 0,
            'data' => ['commandName' => 'App\\Jobs\\ProcessShopifyOrderWebhookJob'],
        ]),
        'attempts' => 0,
        'reserved_at' => $reserved ? now()->getTimestamp() : null,
        'available_at' => now()->subSeconds($availableSecondsAgo)->getTimestamp(),
        'created_at' => now()->subSeconds($availableSecondsAgo)->getTimestamp(),
    ]);
}

function failedJob(string $jobClass = 'App\\Jobs\\SyncStoreProductsJob', string $queue = 'default', string $exception = "RuntimeException: Store credentials rejected\n#0 /app/foo.php(12)"): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $jobClass,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $jobClass],
        ]),
        'exception' => $exception,
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('reports queue depth, in-flight jobs, and failures', function () {
    queueJob();
    queueJob();
    queueJob(reserved: true);
    failedJob();

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/queue/index')
            ->where('metrics.pending', 2)
            ->where('metrics.reserved', 1)
            ->where('metrics.failed', 1)
            ->where('metrics.failedLastDay', 1)
        );
});

it('reports the oldest pending wait as a lag in seconds', function () {
    queueJob(availableSecondsAgo: 600);
    queueJob(availableSecondsAgo: 30);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            // The oldest waiting job sets the lag, not the newest.
            ->where('metrics.oldestPendingSeconds', fn (int $lag) => $lag >= 600 && $lag < 620)
        );
});

it('ignores reserved jobs when computing lag', function () {
    // A job a worker already picked up is not the queue falling behind.
    queueJob(availableSecondsAgo: 9999, reserved: true);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            ->where('metrics.pending', 0)
            ->where('metrics.oldestPendingSeconds', null)
        );
});

it('breaks depth down per queue', function () {
    queueJob('webhooks');
    queueJob('webhooks');
    queueJob('default');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            ->has('queues', 2)
            // Ordered by depth, so the busiest queue leads.
            ->where('queues.0.queue', 'webhooks')
            ->where('queues.0.pending', 2)
            ->where('queues.1.queue', 'default')
        );
});

it('decodes the job class out of the payload without unserializing it', function () {
    failedJob('App\\Jobs\\ProcessYouCanOrderWebhookJob');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            ->where('failed.data.0.jobName', 'ProcessYouCanOrderWebhookJob')
            ->where('failed.data.0.jobClass', 'App\\Jobs\\ProcessYouCanOrderWebhookJob')
        );
});

it('summarises the exception to its first line but keeps the full trace', function () {
    failedJob(exception: "RuntimeException: Boom\n#0 /app/one.php\n#1 /app/two.php");

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertInertia(fn ($page) => $page
            ->where('failed.data.0.exception', 'RuntimeException: Boom')
            ->where('failed.data.0.exceptionFull', "RuntimeException: Boom\n#0 /app/one.php\n#1 /app/two.php")
        );
});

it('survives a payload that is not in the expected shape', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => 'not json at all',
        'exception' => '',
        'failed_at' => now(),
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('failed.data.0.jobName', 'Unknown job')
            ->where('failed.data.0.jobClass', null)
            ->where('failed.data.0.exception', null)
        );
});

it('searches failed jobs by job class and by exception text', function () {
    failedJob('App\\Jobs\\SyncStoreProductsJob', exception: 'RuntimeException: credentials rejected');
    failedJob('App\\Jobs\\ProcessShopifyOrderWebhookJob', exception: 'LogicException: bad payload');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index', ['search' => 'SyncStoreProducts']))
        ->assertInertia(fn ($page) => $page
            ->has('failed.data', 1)
            ->where('failed.data.0.jobName', 'SyncStoreProductsJob')
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index', ['search' => 'bad payload']))
        ->assertInertia(fn ($page) => $page
            ->has('failed.data', 1)
            ->where('failed.data.0.jobName', 'ProcessShopifyOrderWebhookJob')
        );
});

it('filters failed jobs by queue', function () {
    failedJob(queue: 'webhooks');
    failedJob(queue: 'default');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index', ['queue' => 'webhooks']))
        ->assertInertia(fn ($page) => $page
            ->has('failed.data', 1)
            ->where('failed.data.0.queue', 'webhooks')
        );
});

it('paginates failed jobs and caps per_page to the allow-list', function () {
    foreach (range(1, 25) as $ignored) {
        failedJob();
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index', ['per_page' => 10]))
        ->assertInertia(fn ($page) => $page
            ->has('failed.data', 10)
            ->where('failed.total', 25)
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.queue.index', ['per_page' => 5000]))
        ->assertInertia(fn ($page) => $page->where('failed.per_page', 20));
});

it('deletes a single failed job', function () {
    $uuid = failedJob();
    $survivor = failedJob();

    $this->actingAs(superAdmin())
        ->delete(route('super-admin.queue.forget', $uuid))
        ->assertRedirect();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->where('uuid', $survivor)->exists())->toBeTrue();
});

it('404s when deleting a failed job that does not exist', function () {
    $this->actingAs(superAdmin())
        ->delete(route('super-admin.queue.forget', (string) Str::uuid()))
        ->assertNotFound();
});

it('deletes every failed job at once', function () {
    failedJob();
    failedJob();

    $this->actingAs(superAdmin())
        ->delete(route('super-admin.queue.flush'))
        ->assertRedirect();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('404s when retrying a failed job that does not exist', function () {
    $this->actingAs(superAdmin())
        ->post(route('super-admin.queue.retry', (string) Str::uuid()))
        ->assertNotFound();
});

/**
 * The retry path is the one action that can't be verified with a
 * hand-written payload: queue:retry decrypts the serialized command, so
 * only a genuinely dispatched job round-trips. This dispatches a real job,
 * moves it to failed_jobs as a worker would, and retries it.
 */
it('pushes a genuinely failed job back onto its queue', function () {
    config(['queue.default' => 'database']);

    $store = makeStore(makeBusinessUser()->business_id);

    dispatch(new SyncStoreProductsJob($store))->onQueue('products');

    $queued = DB::table('jobs')->firstOrFail();
    $uuid = json_decode($queued->payload, true)['uuid'];

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queued->queue,
        'payload' => $queued->payload,
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);
    DB::table('jobs')->delete();

    $this->actingAs(superAdmin())
        ->post(route('super-admin.queue.retry', $uuid))
        ->assertRedirect();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'products')->count())->toBe(1);
});

it('forbids non super admins from the queue monitor and its actions', function () {
    $uuid = failedJob();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)
        ->get(route('super-admin.queue.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('super-admin.queue.retry', $uuid))
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('super-admin.queue.forget', $uuid))
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('super-admin.queue.flush'))
        ->assertForbidden();

    // Nothing the forbidden user tried actually took effect.
    expect(DB::table('failed_jobs')->count())->toBe(1);
});
