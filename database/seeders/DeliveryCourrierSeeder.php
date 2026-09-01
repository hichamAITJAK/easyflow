<?php

namespace Database\Seeders;

use App\Enums\Courier;
use App\Models\DeliveryCourrier;
use Illuminate\Database\Seeder;

class DeliveryCourrierSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $couriers = [
            [
                'name' => Courier::OZONEXPRESS->value,
                'slug' => Courier::OZONEXPRESS->value,
                'description' => 'Moroccan delivery courier specialized in COD ecommerce shipments.',
                'logo' => '/assets/images/ozonexpress_icon.png',
            ],
            [
                'name' => Courier::SENDIT->value,
                'slug' => Courier::SENDIT->value,
                'description' => 'Moroccan delivery courier offering nationwide COD parcel delivery.',
                'logo' => '/assets/images/sendit_icon.png',
            ],
            [
                'name' => Courier::COLIIX->value,
                'slug' => Courier::COLIIX->value,
                'description' => 'Moroccan delivery courier offering nationwide COD parcel delivery.',
                'logo' => '/assets/images/coliix_icon.png',
            ],
            [
                'name' => Courier::FORCELOG->value,
                'slug' => Courier::FORCELOG->value,
                'description' => 'Moroccan delivery courier offering nationwide COD parcel delivery, pickups and stock handling.',
                'logo' => '/assets/images/forcelog_icon.png',
            ],
            [
                'name' => Courier::AMEEX->value,
                'slug' => Courier::AMEEX->value,
                'description' => 'Moroccan delivery courier offering nationwide COD parcel delivery.',
                'logo' => '/assets/images/ameex_icon.png',
            ],
        ];

        foreach ($couriers as $courier) {
            DeliveryCourrier::updateOrCreate(
                ['slug' => $courier['slug']],
                $courier
            );
        }
    }
}
