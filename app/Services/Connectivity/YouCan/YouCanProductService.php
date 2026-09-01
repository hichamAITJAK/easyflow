<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles all YouCan products-related API calls.
 *
 * Covers: listing products, get by ID / SKU, create, update,
 * categories, and product reviews.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class YouCanProductService extends YouCanHttpClient
{
    // ----------------------------------------------------------------
    // Products
    // ----------------------------------------------------------------

    /**
     * List all products with optional filters.
     *
     * @param  array<string, mixed>  $filters  Optional query parameters (page, per_page, category_id, etc.)
     * @return array<string, mixed>
     */
    public function listProducts(array $filters = []): array
    {
        return $this->get('/products', $filters);
    }

    /**
     * Get a single product by its ID.
     *
     * @param  string  $productId  The product ID
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        return $this->get("/products/{$productId}");
    }

    /**
     * Find a product by its SKU.
     *
     * @param  string  $sku  The product SKU
     * @return array<string, mixed>
     */
    public function getProductBySku(string $sku): array
    {
        return $this->get('/products/sku', [
            'sku' => $sku,
        ]);
    }

    /**
     * Create a new product.
     *
     * @param  array<string, mixed>  $data  Product payload (name, price, variants, etc.)
     * @return array<string, mixed>
     */
    public function createProduct(array $data): array
    {
        return $this->post('/products', $data);
    }

    /**
     * Update an existing product.
     *
     * @param  string  $productId  The product ID to update
     * @param  array<string, mixed>  $data  Fields to update
     * @return array<string, mixed>
     */
    public function updateProduct(string $productId, array $data): array
    {
        return $this->put("/products/{$productId}", $data);
    }

    // ----------------------------------------------------------------
    // Categories
    // ----------------------------------------------------------------

    /**
     * List all product categories.
     *
     * @param  array<string, mixed>  $filters  Optional query parameters
     * @return array<string, mixed>
     */
    public function listCategories(array $filters = []): array
    {
        return $this->get('/products/categories', $filters);
    }

    /**
     * Create a new product category.
     *
     * @param  array<string, mixed>  $data  Category payload (name, parent_id, etc.)
     * @return array<string, mixed>
     */
    public function createCategory(array $data): array
    {
        return $this->post('/products/categories', $data);
    }

    // ----------------------------------------------------------------
    // Product Reviews
    // ----------------------------------------------------------------

    /**
     * List reviews for a specific product.
     *
     * @param  string  $productId  The product ID
     * @param  array<string, mixed>  $filters  Optional query parameters (page, per_page, etc.)
     * @return array<string, mixed>
     */
    public function listReviews(string $productId, array $filters = []): array
    {
        return $this->get("/products/{$productId}/reviews", $filters);
    }

    /**
     * Get a single review by its ID.
     *
     * @param  string  $productId  The product ID
     * @param  string  $reviewId  The review ID
     * @return array<string, mixed>
     */
    public function getReview(string $productId, string $reviewId): array
    {
        return $this->get("/products/{$productId}/reviews/{$reviewId}");
    }

    /**
     * Create a review for a product.
     *
     * @param  string  $productId  The product ID
     * @param  array<string, mixed>  $data  Review payload (rating, comment, author, etc.)
     * @return array<string, mixed>
     */
    public function createReview(string $productId, array $data): array
    {
        return $this->post("/products/{$productId}/reviews", $data);
    }

    /**
     * Delete a product review by its ID.
     *
     * @param  string  $productId  The product ID
     * @param  string  $reviewId  The review ID
     * @return array<string, mixed>
     */
    public function deleteReview(string $productId, string $reviewId): array
    {
        return $this->delete("/products/{$productId}/reviews/{$reviewId}");
    }
}
