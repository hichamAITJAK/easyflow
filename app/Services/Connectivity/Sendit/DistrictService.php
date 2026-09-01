<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage cities, regions, and delivery districts supported by Sendit.
 */
class DistrictService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getDistricts(array $params = []): array
    {
        return $this->get('districts', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getDistrict(int|string $id, array $params = [])
    {
        return $this->get("districts/{$id}", $params);
    }
}
