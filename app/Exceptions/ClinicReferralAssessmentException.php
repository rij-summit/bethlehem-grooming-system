<?php

namespace App\Exceptions;

use RuntimeException;

class ClinicReferralAssessmentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
