<?php

namespace App\Http\Controllers\Concerns;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared search/sort helpers for backend-driven DataTable listings
 * (search box, sortable column headers, pagination all resolved server-side
 * from query-string params, so the frontend never loads more than one page).
 */
trait BuildsTableQuery
{
    /**
     * Apply a `search` query param across the given columns with a single
     * WHERE (col LIKE ? OR col LIKE ? ...) clause.
     *
     * `hashColumn` is for columns whose plaintext is encrypted at rest
     * (e.g. customer_phone) — `LIKE` can't match ciphertext, so search
     * falls back to hashing the input and comparing it against a
     * precomputed hash column (e.g. customer_phone_hash). The input is
     * normalized via PhoneNumber::format() first — the hash was written
     * from that same normalized form, and users search with all sorts of
     * formats (country code, spaces, dashes) — so the raw search string
     * must never be hashed directly, or it'll never match. Still only
     * matches when the full number is typed, not a partial substring.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @return Builder<TModel>
     */
    private function applySearch(Builder $query, Request $request, array $columns, ?string $hashColumn = null): Builder
    {
        $search = $request->string('search')->trim()->toString();

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($columns, $hashColumn, $search) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$search}%");
            }

            $normalizedPhone = PhoneNumber::format($search);

            if ($hashColumn !== null && $normalizedPhone !== null) {
                $query->orWhere($hashColumn, hash('sha256', $normalizedPhone));
            }
        });
    }

    /**
     * Apply a `sort`/`direction` query param, restricted to an allow-list of
     * sortable columns so the request can't sort by an arbitrary/unindexed
     * or non-existent column.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string>  $sortable
     * @param  'asc'|'desc'  $defaultDirection
     * @return Builder<TModel>
     */
    private function applySort(Builder $query, Request $request, array $sortable, string $default = 'created_at', string $defaultDirection = 'desc'): Builder
    {
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, $sortable, true)) {
            $sort = $default;
            $direction = $defaultDirection;
        }

        return $query->orderBy($sort, $direction);
    }

    /**
     * Resolve a comma-separated list of ids from a query param, e.g.
     * `?store_ids=3,7`. Returns an empty array when nothing usable was
     * sent, which callers treat as "no filter" rather than "match
     * nothing".
     *
     * `legacyKey` names the superseded singular param (e.g. `store_id`)
     * and is read only when the plural one is absent, so links and
     * bookmarks saved before these filters became multi-select keep
     * working instead of silently returning an unfiltered list.
     *
     * Values are cast through (int) and zeroes dropped, so a junk or
     * forged param degrades to "no filter" instead of reaching the query
     * builder. Ownership is still enforced by the caller's own business
     * scope — this only sanitises shape, never authorises.
     *
     * @return array<int, int>
     */
    private function resolveIdList(Request $request, string $key, ?string $legacyKey = null): array
    {
        $raw = $request->string($key)->toString();

        if ($raw === '' && $legacyKey !== null) {
            $raw = $request->string($legacyKey)->toString();
        }

        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(intval(...), explode(',', $raw)),
            fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * The same id list as resolveIdList(), rendered back into the
     * comma-separated string the frontend picker binds to — or null when
     * empty, which reads as "no selection" rather than an empty chip.
     *
     * Echoing the normalised value (not the raw param) is what lets a
     * legacy singular link arrive with its control correctly populated.
     */
    private function idListParam(Request $request, string $key, ?string $legacyKey = null): ?string
    {
        $ids = $this->resolveIdList($request, $key, $legacyKey);

        return $ids === [] ? null : implode(',', $ids);
    }

    /**
     * Resolve a `per_page`-style query param, restricted to a small allow-list
     * so a request can't force an unbounded page size. Pass `perPageKey` when
     * a page has more than one paginated table sharing the same URL (each
     * needs its own query-string key to avoid colliding).
     *
     * @param  array<int, int>  $allowed
     */
    private function resolvePerPage(Request $request, array $allowed = [10, 20, 50, 100], int $default = 20, string $perPageKey = 'per_page'): int
    {
        $perPage = $request->integer($perPageKey);

        return in_array($perPage, $allowed, true) ? $perPage : $default;
    }
}
