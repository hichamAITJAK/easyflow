<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage incoming stock movements and inventory additions with Sendit API.
 */
class StockInService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getStockInMovements(array $params = [])
    {
        return $this->get('movements/in', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createStockInMovement(array $data)
    {
        return $this->post('movements/in', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getStockInMovement(string $code, array $params = [])
    {
        return $this->get("movements/in/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateStockInMovement(string $code, array $data)
    {
        return $this->put("movements/in/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteStockInMovement(string $code, array $data = [])
    {
        return $this->delete("movements/in/{$code}", $data);
    }
}
