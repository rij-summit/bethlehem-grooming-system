<?php

namespace Tests\Unit;

use App\Support\PaymentAmountLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentAmountLimitTest extends TestCase
{
    #[DataProvider('paymentAmountLimits')]
    public function test_it_calculates_the_maximum_payment_amount(float $totalDue, float $expectedMaximum): void
    {
        $this->assertSame($expectedMaximum, PaymentAmountLimit::maximumFor($totalDue));
    }

    public static function paymentAmountLimits(): array
    {
        return [
            '₱1,600 total' => [1600.0, 3000.0],
            '₱12,755 total' => [12755.0, 14000.0],
            'exact ₱2,000 total' => [2000.0, 4000.0],
        ];
    }
}
