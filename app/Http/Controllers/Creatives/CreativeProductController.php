<?php

namespace App\Http\Controllers\Creatives;

use App\Enums\CreativeProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CreativeProduct;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Admin-only: a product's identity and lifecycle. */
class CreativeProductController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateBrief($request, withKind: true);

        DB::transaction(function () use ($request, $data) {
            $product = CreativeProduct::create([
                'business_id' => $request->user()->business_id,
                'name' => $data['name'],
                'kind' => $data['kind'],
                'links' => $data['links'],
                'description' => $data['description'] ?? null,
                'status' => CreativeProductStatus::TESTING,
            ]);

            $this->syncEditors($product, $data['editor_ids']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":name" created — now request content for it from the queue.', ['name' => $data['name']])]);

        return back();
    }

    public function update(Request $request, CreativeProduct $product): RedirectResponse
    {
        $data = $this->validateBrief($request, withKind: false);

        DB::transaction(function () use ($product, $data) {
            $product->update([
                'name' => $data['name'],
                'links' => $data['links'],
                'description' => $data['description'] ?? null,
            ]);

            $this->syncEditors($product, $data['editor_ids']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":name" updated.', ['name' => $product->name])]);

        return back();
    }

    /** testing → active (validate) · any → inactive (archive) · inactive → testing / active. */
    public function updateStatus(Request $request, CreativeProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(CreativeProductStatus::class)],
        ]);

        $product->update(['status' => $data['status']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => match (CreativeProductStatus::from($data['status'])) {
            CreativeProductStatus::ACTIVE => __('":name" is now active.', ['name' => $product->name]),
            CreativeProductStatus::INACTIVE => __('":name" archived — reactivate it anytime from Inactive.', ['name' => $product->name]),
            CreativeProductStatus::TESTING => __('":name" is back in testing.', ['name' => $product->name]),
        }]);

        return back();
    }

    /** @return array<string, mixed> */
    private function validateBrief(Request $request, bool $withKind): array
    {
        $businessId = $request->user()->business_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            ...($withKind ? ['kind' => ['required', Rule::in(['single', 'pack'])]] : []),
            'links' => ['required', 'array', 'min:1', 'max:20'],
            'links.*' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'editor_ids' => ['required', 'array', 'min:1'],
            'editor_ids.*' => [
                'integer',
                Rule::exists(User::class, 'id')
                    ->where('business_id', $businessId)
                    ->where('role', UserRole::CREATIVES_EDITOR->value),
            ],
        ]);
    }

    /** @param  array<int, int>  $editorIds */
    private function syncEditors(CreativeProduct $product, array $editorIds): void
    {
        $product->editors()->sync(
            collect($editorIds)->unique()->mapWithKeys(fn (int $id) => [$id => ['business_id' => $product->business_id]])->all(),
        );
    }
}
