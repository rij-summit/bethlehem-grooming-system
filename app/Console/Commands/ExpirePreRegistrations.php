<?php

namespace App\Console\Commands;

use App\Services\PreRegistrationExpiry;
use Illuminate\Console\Command;

class ExpirePreRegistrations extends Command
{
    protected $signature = 'pre-registrations:expire';
    protected $description = 'Expire unused Clinic and Grooming pre-registrations after their selected date ends.';

    public function handle(PreRegistrationExpiry $expiry): void
    {
        $this->info('Expired '.$expiry->expire().' unused pre-registration(s).');
    }
}
