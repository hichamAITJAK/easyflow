<?php

namespace App\Enums;

enum CommissionPaymentMode: string
{
    case SALARY = 'salary';
    case COMMISSION = 'commission';

    /** A fixed salary with a commission on top of it. */
    case SALARY_AND_COMMISSION = 'salary_and_commission';

    /** Whether this mode carries a fixed periodic salary. */
    public function paysSalary(): bool
    {
        return $this !== self::COMMISSION;
    }

    /** Whether this mode earns a commission per order or parcel. */
    public function paysCommission(): bool
    {
        return $this !== self::SALARY;
    }
}
