<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DynamicLucideIconInterfaceTest extends TestCase
{
    public function test_customer_empty_state_icons_remain_mounted_while_switching_tabs(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        $this->assertStringContainsString(
            '<i x-show="statusFilter === \'unregistered\'" data-lucide="user-plus"',
            $page,
        );
        $this->assertStringContainsString(
            '<i x-show="statusFilter !== \'unregistered\'" data-lucide="users"',
            $page,
        );
        $this->assertStringNotContainsString(
            '<template x-if="statusFilter === \'unregistered\'">',
            $page,
        );
    }

    public function test_shared_admin_runtime_refreshes_icons_created_by_conditional_templates(): void
    {
        $sidebar = file_get_contents(base_path('scripts/components/admin-sidebar.js'));

        foreach ([
            'installDynamicLucideIconObserver',
            'new MutationObserver',
            'i[data-lucide]',
            'mutation.addedNodes',
            'window.requestAnimationFrame',
            'window.lucide.createIcons()',
        ] as $guard) {
            $this->assertStringContainsString($guard, $sidebar);
        }
    }

    public function test_every_admin_page_with_conditional_lucide_icons_loads_the_shared_guard(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('pages/admin')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }

            $page = file_get_contents($file->getPathname());
            if (! str_contains($page, 'x-if=') || ! str_contains($page, 'data-lucide=')) {
                continue;
            }

            $this->assertStringContainsString(
                'scripts/components/admin-sidebar.js',
                str_replace('\\', '/', $page),
                "{$file->getFilename()} must load the dynamic Lucide icon guard.",
            );
        }
    }
}
