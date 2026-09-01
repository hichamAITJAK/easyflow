<?php

namespace Database\Factories;

use App\Models\AgentScope;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentScope>
 *
 * Defaults to no scope (null store_id and product_id), which means the
 * agent has access to all stores and products. Use forStore() / forProduct()
 * to constrain to a specific resource.
 */
class AgentScopeFactory extends Factory
{
    protected $model = AgentScope::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'store_id' => null,
            'product_id' => null,
        ];
    }
}
