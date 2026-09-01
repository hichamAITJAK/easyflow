<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to manage billing, statements, and delivery invoices with Sendit API.
 */
class InvoiceService extends SenditHttpClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getInvoices(array $params = [])
    {
        return $this->get('invoices', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getInvoice(string $code, array $params = [])
    {
        return $this->get("invoices/{$code}", $params);
    }
}
