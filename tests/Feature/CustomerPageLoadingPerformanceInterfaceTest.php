<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerPageLoadingPerformanceInterfaceTest extends TestCase
{
    public function test_dashboard_prioritizes_visible_content_and_guards_background_refreshes(): void
    {
        $dashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));

        foreach ([
            'function scheduleCustomerDashboardIdleTask(task)',
            'void loadAppointments();',
            'await loadDashboardPets();',
            'await loadGroomingCapacity();',
            'let notificationsLoading = false;',
            'let appointmentsLoading = false;',
            'let groomingCapacityLoading = false;',
            'document.visibilityState === "visible"',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $dashboard);
        }
    }

    public function test_my_pets_loads_the_visible_collection_then_prefetches_and_caches_the_other(): void
    {
        $myPets = file_get_contents(base_path('scripts/components/my-pets.js'));

        foreach ([
            'let activePetsCache = null;',
            'let archivedPetsCache = null;',
            'let petCollectionGeneration = 0;',
            'async function loadPetCollection(archived, { force = false } = {})',
            'if (!force && cachedPets !== null) return cachedPets;',
            'function schedulePetCollectionPrefetch()',
            'loadPetCollection(!showingArchived)',
            'function invalidatePetCollections()',
            'if (showingArchived !== archived) return;',
            'await Promise.allSettled([',
            'let notificationsLoaded = false;',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $myPets);
        }

        $this->assertStringNotContainsString(
            'const [activeData, archivedData] = await Promise.all([',
            $myPets,
        );
    }

    public function test_grooming_history_prioritizes_history_and_reuses_the_loaded_result(): void
    {
        $history = file_get_contents(base_path('pages/client/grooming-history.html'));

        foreach ([
            'let historyLoadState = "idle";',
            'let historyLoadPromise = null;',
            'if (historyLoadState === "loaded") return Promise.resolve(allHistory);',
            'void loadHistory();',
            'scheduleCustomerHistoryIdleTask(loadCustomerProfile)',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $history);
        }
    }

    public function test_settings_and_pet_profile_defer_secondary_customer_data(): void
    {
        $settings = file_get_contents(base_path('scripts/components/client-settings.js'));
        $petProfile = file_get_contents(base_path('scripts/components/pet-details.js'));

        foreach ([
            'let profileLoadPromise = null;',
            'scheduleSettingsIdleTask(loadProfile)',
            'if (profileLoadPromise) return profileLoadPromise;',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $settings);
        }

        foreach ([
            'const petTabLoaders = {',
            'scheduleCustomerTabPrefetch',
            'void loadPet();',
            'scheduleIdleTask(loadProfile)',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $petProfile);
        }
    }

    public function test_shared_customer_controls_do_not_compete_with_first_paint(): void
    {
        $preRegistration = file_get_contents(
            base_path('scripts/components/customer-pre-registration-button.js'),
        );
        $chatbot = file_get_contents(base_path('scripts/components/home-ai-chatbot.js'));

        foreach ([
            'let accessRequest = null;',
            'window.requestIdleCallback(start, { timeout: 1200 });',
            'window.requestAnimationFrame(scheduleAccessCheck);',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $preRegistration);
        }

        $submitHandler = $this->sourceBetween(
            $chatbot,
            'async function handleChatSubmit(event)',
            'function rememberConversationMessage',
        );
        $this->assertStringContainsString('API.sendChatbotMessage(', $submitHandler);
        $this->assertStringContainsString('chatSend.disabled = isLoading;', $chatbot);
        $this->assertStringNotContainsString('API.sendChatbotMessage(', substr(
            $chatbot,
            0,
            strpos($chatbot, 'async function handleChatSubmit(event)'),
        ));
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
