<?php

use App\DTOs\OzonExpress\OzonExpressNewParcelDTO;

test('parcel-city carries the destination city external courier id, not its name', function () {
    $dto = new OzonExpressNewParcelDTO(
        receiver: 'Jane Doe',
        phone: '0600000000',
        cityId: 37,
        address: '123 Main St',
        price: 199.0,
        stock: false,
    );

    expect($dto->toArray()['parcel-city'])->toBe(37);
});
