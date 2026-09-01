<?php

namespace Database\Factories;

use App\Models\EcommercePlatform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EcommercePlatform>
 *
 * Note: In most tests you'll want to use EcommercePlatform::firstOrCreate()
 * with a known slug (e.g. 'youcan') rather than this factory, because the
 * platform rows are seeded once. Use the factory for isolated unit tests
 * that don't rely on real platform slugs.
 */
class EcommercePlatformFactory extends Factory
{
    protected $model = EcommercePlatform::class;

    /** @var array<int, string> */
    private static array $platforms = ['YouCan', 'Shopify', 'LightFunnels', 'WooCommerce'];

    public function definition(): array
    {
        $name = $this->faker->unique()->randomElement(self::$platforms).' '.$this->faker->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'logo_url' => null,
        ];
    }
}
