<?php

namespace App\Services\Connectivity\Sendit;

class SenditClient
{
    protected string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function parcel(): ParcelService
    {
        return new ParcelService($this->token);
    }

    public function stockIn(): StockInService
    {
        return new StockInService($this->token);
    }

    public function stockOut(): StockOutService
    {
        return new StockOutService($this->token);
    }

    public function return(): ReturnService
    {
        return new ReturnService($this->token);
    }

    public function pickup(): PickupService
    {
        return new PickupService($this->token);
    }

    public function packaging(): PackagingService
    {
        return new PackagingService($this->token);
    }

    public function district(): DistrictService
    {
        return new DistrictService($this->token);
    }

    public function invoice(): InvoiceService
    {
        return new InvoiceService($this->token);
    }

    public function auth(): AuthService
    {
        return new AuthService($this->token);
    }
}
