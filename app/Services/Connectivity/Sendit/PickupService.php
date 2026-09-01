<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to schedule and manage shipment pickups with Sendit API.
 */
class PickupService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getPickups(array $params = [])
    {
        return $this->get('pickups', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPickup(array $data)
    {
        return $this->post('pickups', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getPickup(string $code, array $params = [])
    {
        return $this->get("pickups/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePickup(string $code, array $data)
    {
        return $this->put("pickups/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deletePickup(string $code, array $data = [])
    {
        return $this->delete("pickups/{$code}", $data);
    }
}
