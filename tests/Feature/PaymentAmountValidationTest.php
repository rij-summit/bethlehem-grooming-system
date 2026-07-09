<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAmountValidationTest extends TestCase
{
    #[DataProvider('paymentsAboveMaximum')]
    public function test_it_rejects_a_payment_above_the_total_based_maximum(
        float $totalDue,
        float $amountPaid,
        string $expectedMessage,
    ): void {
        $request = Request::create('/api/admin/bookings/1/pay', 'POST', [
            'final_price' => $totalDue,
            'amount_paid' => $amountPaid,
        ]);

        try {
            (new PaymentController)->store($request, 1);
            $this->fail('The payment amount should have failed validation.');
        } catch (ValidationException $exception) {
            $this->assertSame($expectedMessage, $exception->errors()['amount_paid'][0]);
        }
    }

    public static function paymentsAboveMaximum(): array
    {
        return [
            '₱1,600 total' => [1600.0, 3000.01, 'Amount paid cannot exceed ₱3,000.00.'],
            '₱12,755 total' => [12755.0, 14000.01, 'Amount paid cannot exceed ₱14,000.00.'],
        ];
    }
}
