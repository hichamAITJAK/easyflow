<?php

namespace App\Services\Connectivity\WooCommerce;

/**
 * WooCommerce store-level endpoints — used for connect-time verification
 * and for reading back the store's own details.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#system-status
 */
class WooCommerceSystemService extends WooCommerceHttpClient
{
    /**
     * Full system status: environment, database, active plugins, theme.
     *
     * `environment.site_url` and `environment.version` are what we read the
     * store's name/URL and its WooCommerce version from. This endpoint needs
     * a read-capable key, so a 200 here also proves the credentials work.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->get('/system_status');
    }

    /**
     * The store's configured currency code, e.g. "MAD".
     *
     * @return array<string, mixed>
     */
    public function currentCurrency(): array
    {
        return $this->get('/data/currencies/current');
    }
}
