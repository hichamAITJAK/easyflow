<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage parcel packaging options and package types with Sendit API.
 */
class PackagingService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getPackagings(array $params = [])
    {
        return $this->get('packagings', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getPackaging(string $code, array $params = [])
    {
        return $this->get("packagings/{$code}", $params);
    }
}
