<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage deliveries, parcel creation, tracking, and labels generation with Sendit API.
 */
class ParcelService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getParcels(array $params = [])
    {
        return $this->get('deliveries', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createParcel(array $data)
    {
        return $this->post('deliveries', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getParcel(string $code, array $params = [])
    {
        return $this->get("deliveries/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateParcel(string $code, array $data)
    {
        return $this->put("deliveries/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteParcel(string $code, array $data = [])
    {
        return $this->delete("deliveries/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function getLabels(array $data)
    {
        return $this->post('deliveries/getlabels', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getStatuses(array $params = [])
    {
        return $this->get('all-status-deliveries', $params);
    }
}
