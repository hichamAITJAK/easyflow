<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles all YouCan customers-related API calls.
 *
 * Covers: listing customers, customer details (optionally including their
 * orders and/or addresses), create, update, delete, and address management.
 *
 * Docs: https://developer.youcan.shop/store-admin/customers
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class YouCanCustomerService extends YouCanHttpClient
{
    // ----------------------------------------------------------------
    // Customers
    // ----------------------------------------------------------------

    /**
     * List customers with optional search, subresources, and pagination.
     *
     * @param  array<string, mixed>  $filters  Optional query parameters:
     *                                         - q: search across first/last name, phone, email
     *                                         - include: comma-separated subresources ("address", "orders")
     *                                         - page: page number
     * @return array<string, mixed>
     */
    public function listCustomers(array $filters = []): array
    {
        return $this->get('/customers', $filters);
    }

    /**
     * Get details for a single customer.
     *
     * @param  string  $customerId  The customer ID
     * @param  array<int, string>  $include  Subresources to embed, e.g. ['address', 'orders']
     * @return array<string, mixed>
     */
    public function getCustomer(string $customerId, array $include = []): array
    {
        return $this->get("/customers/{$customerId}", $this->includeQuery($include));
    }

    /**
     * Get a customer along with their full order history.
     *
     * @param  string  $customerId  The customer ID
     * @return array<string, mixed>
     */
    public function getCustomerWithOrders(string $customerId): array
    {
        return $this->getCustomer($customerId, ['address', 'orders']);
    }

    /**
     * Create a new customer.
     *
     * @param  array<string, mixed>  $data  Customer payload (first_name, last_name, email, phone, address, etc.)
     * @return array<string, mixed>
     */
    public function createCustomer(array $data): array
    {
        return $this->post('/customers', $data);
    }

    /**
     * Update an existing customer.
     *
     * @param  string  $customerId  The customer ID to update
     * @param  array<string, mixed>  $data  Fields to update
     * @return array<string, mixed>
     */
    public function updateCustomer(string $customerId, array $data): array
    {
        return $this->put("/customers/{$customerId}", $data);
    }

    /**
     * Delete a customer.
     *
     * @param  string  $customerId  The customer ID to delete
     * @return array<string, mixed>
     */
    public function deleteCustomer(string $customerId): array
    {
        return $this->delete("/customers/{$customerId}");
    }

    // ----------------------------------------------------------------
    // Customer Addresses
    // ----------------------------------------------------------------

    /**
     * Add a new address to a customer.
     *
     * @param  string  $customerId  The customer ID
     * @param  array<string, mixed>  $data  Address payload (first_name, last_name, company, phone,
     *                                      first_line, second_line, region, city, zip_code,
     *                                      country_code, is_default)
     * @return array<string, mixed>
     */
    public function createAddress(string $customerId, array $data): array
    {
        return $this->post("/customers/{$customerId}/addresses", $data);
    }

    /**
     * Update an existing address on a customer.
     *
     * @param  string  $customerId  The customer ID
     * @param  string  $addressId  The address ID to update
     * @param  array<string, mixed>  $data  Fields to update
     * @return array<string, mixed>
     */
    public function updateAddress(string $customerId, string $addressId, array $data): array
    {
        return $this->put("/customers/{$customerId}/addresses/{$addressId}", $data);
    }

    /**
     * Build the `include` query parameter from a list of subresource names.
     *
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    private function includeQuery(array $include): array
    {
        return $include === [] ? [] : ['include' => implode(',', $include)];
    }
}
