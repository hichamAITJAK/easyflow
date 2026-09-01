<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrains queries to the authenticated user's business as a defense-in-depth
 * layer, on top of the explicit business_id checks already done in controllers.
 *
 * @implements Scope<Model>
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && $user->business_id !== null) {
            $builder->where($model->qualifyColumn('business_id'), $user->business_id);
        }
    }
}
