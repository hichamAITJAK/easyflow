<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage products and inventory stock catalogs synced with Sendit.
 */
class ProductService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getProducts(array $params = [])
    {
        return $this->get('products', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createProduct(array $data)
    {
        return $this->post('products', $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getProduct(string $code, array $params = [])
    {
        return $this->get("products/{$code}", $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateProduct(string $code, array $data)
    {
        return $this->put("products/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteProduct(string $code, array $data = [])
    {
        return $this->delete("products/{$code}", $data);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getProductMovements(string $code, array $params = [])
    {
        return $this->get("products/{$code}/movements", $params);
    }
}
