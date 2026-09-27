<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminCustomerDeletionTest extends TestCase
{
    public function test_registered_and_unregistered_customer_delete_endpoints_are_unavailable(): void
    {
        $this->deleteJson('/api/admin/customers/10')->assertStatus(405);
        $this->deleteJson('/api/admin/customers/unregistered/30')->assertStatus(405);
    }
}
