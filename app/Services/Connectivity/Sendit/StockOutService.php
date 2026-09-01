<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage outgoing stock movements, inventory dispatch, and withdrawals with Sendit API.
 */
class StockOutService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getStockOutMovements(array $params = [])
    {
        return $this->get('movements/out', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createStockOutMovement(array $data)
    {
        return $this->post('movements/out', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getStockOutMovement(string $code, array $params = [])
    {
        return $this->get("movements/out/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateStockOutMovement(string $code, array $data)
    {
        return $this->put("movements/out/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteStockOutMovement(string $code, array $data = [])
    {
        return $this->delete("movements/out/{$code}", $data);
    }
}
