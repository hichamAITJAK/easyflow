<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage returns, exchange requests, and reverse logistics with Sendit API.
 */
class ReturnService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getReturns(array $params = [])
    {
        return $this->get('returns', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createReturn(array $data)
    {
        return $this->post('returns', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getReturn(string $code, array $params = [])
    {
        return $this->get("returns/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateReturn(string $code, array $data)
    {
        return $this->put("returns/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteReturn(string $code, array $data = [])
    {
        return $this->delete("returns/{$code}", $data);
    }
}
