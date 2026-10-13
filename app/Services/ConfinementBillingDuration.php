<?php

namespace App\Services;

use Carbon\CarbonInterface;

class ConfinementBillingDuration
{
    public function billableDays(CarbonInterface $admittedAt, CarbonInterface $dischargedAt): int
    {
        return max(1, (int) ceil(($dischargedAt->getTimestamp() - $admittedAt->getTimestamp()) / 86400));
    }
}
