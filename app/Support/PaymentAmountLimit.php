<?php

namespace App\Support;

final class PaymentAmountLimit
{
    public const BILL_DENOMINATION = 1000;

    public static function maximumFor(float $totalDue): float
    {
        if ($totalDue <= 0) {
            return 0;
        }

        return floor($totalDue / self::BILL_DENOMINATION) * self::BILL_DENOMINATION
            + self::BILL_DENOMINATION * 2;
    }
}
