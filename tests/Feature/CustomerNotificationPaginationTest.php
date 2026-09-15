<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerNotificationController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerNotificationPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->unsignedInteger('grooming_medical_concern_id')->nullable();
            $table->unsignedInteger('grooming_clinic_referral_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read');
            $table->timestamp('created_at');
        });

        for ($number = 1; $number <= 35; $number++) {
            DB::table('customer_notifications')->insert([
                'user_id' => 1,
                'type' => 'reminder_24h',
                'message' => "Notification {$number}",
                'is_read' => $number % 2 === 0,
                'created_at' => sprintf('2026-09-15 10:%02d:00', $number),
            ]);
        }
        DB::table('customer_notifications')->insert([
            'user_id' => 2,
            'type' => 'reminder_24h',
            'message' => 'Another customer',
            'is_read' => false,
            'created_at' => '2026-09-15 11:00:00',
        ]);
    }

    public function test_client_full_page_can_load_older_notifications_and_filter_unread(): void
    {
        $controller = new CustomerNotificationController;
        $first = $controller->index($this->customerRequest(['sort' => 'recent']))->getData(true);
        $second = $controller->index($this->customerRequest(['sort' => 'recent', 'page' => 2]))->getData(true);
        $unread = $controller->index($this->customerRequest(['sort' => 'recent', 'status' => 'unread']))->getData(true);

        $this->assertCount(30, $first['notifications']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(5, $second['notifications']);
        $this->assertFalse($second['has_more']);
        $this->assertSame('Notification 35', $first['notifications'][0]['message']);
        $this->assertSame('Notification 5', $second['notifications'][0]['message']);
        $this->assertSame(18, $first['unread_count']);
        $this->assertCount(18, $unread['notifications']);
        $this->assertTrue(collect($unread['notifications'])->every(fn ($item) => ! $item['is_read']));
    }

    public function test_mark_all_read_still_updates_notifications_beyond_first_page(): void
    {
        $controller = new CustomerNotificationController;
        $controller->markAllRead($this->customerRequest());

        $this->assertSame(0, DB::table('customer_notifications')->where('user_id', 1)->where('is_read', false)->count());
        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', 2)->where('is_read', false)->count());
    }

    private function customerRequest(array $query = []): Request
    {
        $request = Request::create('/api/customer/notifications', 'GET', $query);
        $request->setUserResolver(fn () => (object) ['user_id' => 1]);

        return $request;
    }
}
