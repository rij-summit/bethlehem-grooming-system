<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PaymentLimitExceededException extends Exception
{
    public const MAX_DIGITS = 6;
    public const MAX_VALUE  = 999999;

    public function __construct(string $field)
    {
        parent::__construct(
            "The {$field} exceeds the maximum allowed amount of ₱" . number_format(self::MAX_VALUE) . " (6 digits)."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error'   => 'PAYMENT_LIMIT_EXCEEDED',
        ], 422);
    }
}
