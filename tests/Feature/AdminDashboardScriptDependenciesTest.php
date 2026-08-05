<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardScriptDependenciesTest extends TestCase
{
    public function test_dashboard_loads_each_state_dependency_before_the_dashboard_component(): void
    {
        $page = file_get_contents(base_path('pages/admin/dashboard.html'));

        $dashboardPosition = strpos(
            $page,
            'scripts/components/admin-dashboard.js?v=stopped-payment-review-ui-20260803',
        );

        $this->assertNotFalse($dashboardPosition);

        foreach ([
            'scripts/components/admin-grooming-concerns.js?v=medical-concern-ui-20260724',
            'scripts/components/admin-clinic-referrals.js?v=clinic-referral-ui-20260804',
            'scripts/components/admin-stopped-payment-review.js?v=stopped-payment-review-ui-20260803',
        ] as $dependency) {
            $dependencyPosition = strpos($page, $dependency);

            $this->assertNotFalse($dependencyPosition, "Missing dashboard dependency: {$dependency}");
            $this->assertLessThan(
                $dashboardPosition,
                $dependencyPosition,
                "Dashboard dependency must load first: {$dependency}",
            );
        }

        $this->assertStringContainsString(
            'scripts/api.js?v=medical-concern-ui-20260724',
            $page,
        );
    }
}
