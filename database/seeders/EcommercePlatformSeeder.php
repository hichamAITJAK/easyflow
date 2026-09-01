<?php

namespace Database\Seeders;

use App\Enums\EcomPlatform;
use App\Models\EcommercePlatform;
use Illuminate\Database\Seeder;

class EcommercePlatformSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $platforms = [
            [
                'name' => EcomPlatform::SHOPIFY->value,
                'slug' => EcomPlatform::SHOPIFY->value,
                'description' => 'Global hosted ecommerce platform for building and managing online stores.',
                'logo_url' => '/assets/images/shopify_icon.png',
            ],
            [
                'name' => EcomPlatform::YOUCAN->value,
                'slug' => EcomPlatform::YOUCAN->value,
                'description' => 'Moroccan ecommerce platform tailored for local and COD-based online stores.',
                'logo_url' => '/assets/images/youcan_logo.svg',
            ],
            [
                'name' => EcomPlatform::WOOCOMMERCE->value,
                'slug' => EcomPlatform::WOOCOMMERCE->value,
                'description' => 'Self-hosted ecommerce plugin for WordPress, connected with REST API keys.',
                'logo_url' => '/assets/images/woocommerce_icon.png',
            ],
            [
                'name' => EcomPlatform::LIGHTFUNNELS->value,
                'slug' => EcomPlatform::LIGHTFUNNELS->value,
                'description' => 'Ecommerce platform with built-in CRM and marketing automation.',
                'logo_url' => '/assets/images/lightfunnels_icon.png',
            ],
            [
                'name' => EcomPlatform::STOREEP->value,
                'slug' => EcomPlatform::STOREEP->value,
                'description' => 'Ecommerce platform for multi-market stores, connected with an access token.',
                'logo_url' => '/assets/images/storeep_icon.png',
            ],
        ];

        foreach ($platforms as $platform) {
            EcommercePlatform::updateOrCreate(
                ['slug' => $platform['slug']],
                $platform
            );
        }
    }
}
