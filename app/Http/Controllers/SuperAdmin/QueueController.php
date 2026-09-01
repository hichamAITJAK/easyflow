<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\QueuePayload;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-level view of the queue, read straight from the `jobs` and
 * `failed_jobs` tables.
 *
 * Deliberately not Horizon: the PRD reserves Redis for cache and rate
 * limiting and requires a durable, database-backed queue, and Horizon only
 * monitors Redis queues.
 */
class QueueController extends Controller
{
    /**
     * Page sizes the failed-jobs table may use.
     *
     * @var list<int>
     */
    private const PER_PAGE = [10, 20, 50, 100];

    public function index(Request $request): Response
    {
        return Inertia::render('super-admin/queue/index', [
            'metrics' => $this->metrics(),
            'queues' => $this->queueBreakdown(),
            'failed' => $this->failedJobs($request),
            'filters' => $request->only(['search', 'queue', 'per_page']),
            'failedQueues' => DB::table('failed_jobs')
                ->distinct()
                ->orderBy('queue')
                ->pluck('queue')
                ->all(),
            'connection' => config('queue.default'),
        ]);
    }

    /**
     * Retry a single failed job by pushing it back onto its queue.
     */
    public function retry(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job pushed back onto its queue.')]);

        return back();
    }

    /**
     * Retry every failed job at once.
     */
    public function retryAll(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return back();
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('1 job pushed back onto its queue.|:count jobs pushed back onto their queues.', $count, ['count' => $count]),
        ]);

        return back();
    }

    /**
     * Discard a failed job. The payload goes with it, so this is the one
     * destructive action on the page.
     */
    public function forget(string $uuid): RedirectResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        abort_if($deleted === 0, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Failed job deleted.')]);

        return back();
    }

    /**
     * Discard every failed job.
     */
    public function flush(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();

        DB::table('failed_jobs')->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('1 failed job deleted.|:count failed jobs deleted.', $count, ['count' => $count]),
        ]);

        return back();
    }

    /**
     * Headline numbers: how much work is waiting, how much is in flight,
     * how far behind the workers are, and how much has failed.
     *
     * @return array<string, int|string|null>
     */
    private function metrics(): array
    {
        $pending = DB::table('jobs')->whereNull('reserved_at')->count();
        $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();

        // The oldest unreserved job's availability is the queue's lag: how
        // long the next piece of work has already been waiting. A rising
        // number here means workers aren't keeping up (or aren't running).
        $oldestAvailableAt = DB::table('jobs')
            ->whereNull('reserved_at')
            ->min('available_at');

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'failed' => DB::table('failed_jobs')->count(),
            'failedLastDay' => DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
            'oldestPendingSeconds' => $oldestAvailableAt === null
                ? null
                : max(0, now()->getTimestamp() - (int) $oldestAvailableAt),
        ];
    }

    /**
     * Per-queue depth, so one backed-up queue doesn't hide behind a healthy
     * total.
     *
     * @return list<array<string, mixed>>
     */
    private function queueBreakdown(): array
    {
        $rows = DB::table('jobs')
            ->select('queue')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when reserved_at is null then 1 else 0 end) as pending')
            ->selectRaw('min(available_at) as oldest_available_at')
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'queue' => $row->queue,
                'total' => (int) $row->total,
                'pending' => (int) $row->pending,
                'oldestPendingSeconds' => $row->oldest_available_at === null
                    ? null
                    : max(0, now()->getTimestamp() - (int) $row->oldest_available_at),
            ])
            ->all();

        // array_values(): map() preserves the source keys, and this is
        // serialized to JSON — a non-sequential array would encode as an
        // object rather than the array the client expects.
        return array_values($rows);
    }

    /**
     * The failed-jobs table: searchable by job class or exception message,
     * filterable by queue, paginated server-side.
     *
     * @return LengthAwarePaginator<int|string, array<string, mixed>>
     */
    private function failedJobs(Request $request): LengthAwarePaginator
    {
        $search = $request->string('search')->trim()->toString();
        $queue = $request->string('queue')->trim()->toString();

        $perPage = $request->integer('per_page');
        $perPage = in_array($perPage, self::PER_PAGE, true) ? $perPage : 20;

        $paginator = DB::table('failed_jobs')
            ->when($queue !== '', fn (Builder $query) => $query->where('queue', $queue))
            // Both columns are searched as raw text: the job class lives
            // inside the JSON payload, so there is no column to match on.
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $group) => $group
                    ->where('payload', 'like', "%{$search}%")
                    ->orWhere('exception', 'like', "%{$search}%"),
            ))
            ->orderByDesc('failed_at')
            ->paginate($perPage)
            ->withQueryString();

        // Mapped after the fact rather than through `through()`: the query
        // builder's paginator types its items as `mixed`, so the closure
        // param would degrade to a bare `object` with no known properties.
        return $paginator->setCollection(
            $paginator->getCollection()->map(fn (mixed $row): array => [
                'uuid' => $row->uuid,
                'jobName' => QueuePayload::jobName($row->payload) ?? __('Unknown job'),
                'jobClass' => QueuePayload::jobClass($row->payload),
                'queue' => $row->queue,
                'connection' => $row->connection,
                'failedAt' => $row->failed_at,
                'exception' => QueuePayload::exceptionSummary($row->exception),
                'exceptionFull' => $row->exception,
            ])
        );
    }
}
