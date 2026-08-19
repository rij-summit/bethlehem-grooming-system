<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        Storage::fake('local');
        Storage::fake('public');

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Staff',
                'last_name' => 'Member',
                'email' => 'staff@example.test',
                'phone' => '09170000001',
                'role' => 'staff',
                'is_active' => true,
                'is_archived' => false,
                'email_verified_at' => now(),
                'profile_photo' => null,
            ],
            [
                'user_id' => 10,
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'email' => 'maria@example.test',
                'phone' => '09170000010',
                'role' => 'customer',
                'is_active' => true,
                'is_archived' => false,
                'email_verified_at' => now(),
                'profile_photo' => 'profile-photos/maria.jpg',
            ],
            [
                'user_id' => 20,
                'first_name' => 'Other',
                'last_name' => 'Owner',
                'email' => 'other@example.test',
                'phone' => '09170000020',
                'role' => 'customer',
                'is_active' => true,
                'is_archived' => false,
                'email_verified_at' => now(),
                'profile_photo' => 'profile-photos/other.jpg',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_name_must_match_exactly_before_a_registered_customer_can_be_deleted(): void
    {
        $this->authenticateAsStaff();

        $this->deleteJson('/api/admin/customers/10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_name');

        $this->deleteJson('/api/admin/customers/10', [
            'confirmation_name' => 'maria santos',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_name');

        $this->assertDatabaseHas('users', ['user_id' => 10]);
    }

    public function test_staff_can_delete_account_access_while_retaining_the_registered_owner_history_graph(): void
    {
        $target = $this->seedOwnerGraph(10, null, 100);
        $other = $this->seedOwnerGraph(20, null, 200);

        DB::table('personal_access_tokens')->insert([
            [
                'id' => 1,
                'tokenable_type' => User::class,
                'tokenable_id' => 10,
            ],
            [
                'id' => 2,
                'tokenable_type' => User::class,
                'tokenable_id' => 20,
            ],
        ]);
        DB::table('sessions')->insert([
            ['id' => 'target-session', 'user_id' => 10],
            ['id' => 'other-session', 'user_id' => 20],
        ]);
        DB::table('pending_customer_registrations')->insert([
            ['id' => 1, 'email' => 'maria@example.test', 'phone' => '09170000010'],
            ['id' => 2, 'email' => 'other@example.test', 'phone' => '09170000020'],
        ]);

        Storage::disk('local')->put('profile-photos/maria.jpg', 'target profile');
        Storage::disk('local')->put('profile-photos/other.jpg', 'other profile');

        $this->authenticateAsStaff();
        $this->deleteJson('/api/admin/customers/10', [
            'confirmation_name' => 'Maria Santos',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Customer account deleted. Historical owner, pet, grooming, and clinic records were retained.',
            );

        $this->assertDatabaseHas('users', [
            'user_id' => 10,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'is_active' => false,
            'is_archived' => true,
            'profile_photo' => null,
        ]);
        $deletedCustomer = DB::table('users')->where('user_id', 10)->first();
        $this->assertNotNull($deletedCustomer->account_deleted_at);
        $this->assertNotSame('maria@example.test', $deletedCustomer->email);
        $this->assertNotSame('09170000010', $deletedCustomer->phone);
        $this->assertGraphStillExists($target);
        $this->assertGraphStillExists($other);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => 10]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => 20]);
        $this->assertDatabaseMissing('sessions', ['user_id' => 10]);
        $this->assertDatabaseHas('sessions', ['user_id' => 20]);
        $this->assertDatabaseMissing('pending_customer_registrations', [
            'email' => 'maria@example.test',
        ]);
        $this->assertDatabaseHas('pending_customer_registrations', [
            'email' => 'other@example.test',
        ]);
        Storage::disk('local')->assertMissing('profile-photos/maria.jpg');
        Storage::disk('local')->assertExists($target['attachment_path']);
        Storage::disk('local')->assertExists('profile-photos/other.jpg');
        Storage::disk('local')->assertExists($other['attachment_path']);
        $this->assertNull(app(CustomerIdentityService::class)->findContactConflict(
            '09170000010',
            'maria@example.test',
        ));
        DB::table('users')->insert([
            'user_id' => 30,
            'first_name' => 'Replacement',
            'last_name' => 'Account',
            'email' => 'maria@example.test',
            'phone' => '09170000010',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
        $this->assertDatabaseHas('users', [
            'user_id' => 30,
            'email' => 'maria@example.test',
            'phone' => '09170000010',
        ]);

        $this->getJson('/api/admin/customers?status=archived')
            ->assertOk()
            ->assertJsonMissing(['id' => 10]);
    }

    public function test_staff_can_delete_inactive_and_archived_registered_accounts(): void
    {
        DB::table('users')->insert([
            [
                'user_id' => 11,
                'first_name' => 'Inactive',
                'last_name' => 'Owner',
                'email' => 'inactive@example.test',
                'phone' => '09170000011',
                'role' => 'customer',
                'is_active' => false,
                'is_archived' => false,
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 12,
                'first_name' => 'Archived',
                'last_name' => 'Owner',
                'email' => 'archived@example.test',
                'phone' => '09170000012',
                'role' => 'customer',
                'is_active' => false,
                'is_archived' => true,
                'email_verified_at' => now(),
            ],
        ]);

        $this->authenticateAsStaff();

        $this->deleteJson('/api/admin/customers/11', [
            'confirmation_name' => 'Inactive Owner',
        ])->assertOk();
        $this->deleteJson('/api/admin/customers/12', [
            'confirmation_name' => 'Archived Owner',
        ])->assertOk();

        $this->assertNotNull(DB::table('users')->where('user_id', 11)->value('account_deleted_at'));
        $this->assertNotNull(DB::table('users')->where('user_id', 12)->value('account_deleted_at'));
        $this->assertDatabaseHas('users', ['user_id' => 11, 'is_active' => false, 'is_archived' => true]);
        $this->assertDatabaseHas('users', ['user_id' => 12, 'is_active' => false, 'is_archived' => true]);
    }

    public function test_staff_can_delete_an_archived_unregistered_owner_using_the_displayed_full_name(): void
    {
        DB::table('unregistered_customers')->insert([
            'id' => 30,
            'first_name' => 'Ana',
            'middle_name' => 'Q',
            'last_name' => 'Reyes',
            'phone' => '09170000030',
            'email' => 'ana@example.test',
            'is_archived' => true,
        ]);
        $target = $this->seedOwnerGraph(null, 30, 300);
        $other = $this->seedOwnerGraph(20, null, 400);

        DB::table('pending_customer_registrations')->insert([
            'id' => 3,
            'email' => 'ana@example.test',
            'phone' => '09170000030',
        ]);

        $this->authenticateAsStaff();

        $this->deleteJson('/api/admin/customers/unregistered/30', [
            'confirmation_name' => 'Ana Reyes',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_name');

        $this->deleteJson('/api/admin/customers/unregistered/30', [
            'confirmation_name' => 'Ana Q. Reyes',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('unregistered_customers', [
            'id' => 30,
            'first_name' => 'Ana',
            'middle_name' => 'Q',
            'last_name' => 'Reyes',
            'is_archived' => true,
        ]);
        $deletedCustomer = DB::table('unregistered_customers')->where('id', 30)->first();
        $this->assertNotNull($deletedCustomer->account_deleted_at);
        $this->assertNotSame('ana@example.test', $deletedCustomer->email);
        $this->assertNotSame('09170000030', $deletedCustomer->phone);
        $this->assertGraphStillExists($target);
        $this->assertGraphStillExists($other);
        $this->assertDatabaseMissing('pending_customer_registrations', [
            'email' => 'ana@example.test',
        ]);
        Storage::disk('local')->assertExists($target['attachment_path']);
        Storage::disk('local')->assertExists($other['attachment_path']);
        $this->assertNull(app(CustomerIdentityService::class)->findContactConflict(
            '09170000030',
            'ana@example.test',
        ));

        $this->getJson('/api/admin/customers?status=archived')
            ->assertOk()
            ->assertJsonMissing(['id' => 30, 'recordType' => 'unregistered']);
    }

    public function test_customer_role_cannot_delete_owner_accounts(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(10), ['*']);

        $this->deleteJson('/api/admin/customers/20', [
            'confirmation_name' => 'Other Owner',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('users', ['user_id' => 20]);
    }

    /** @return array<string, int|string> */
    private function seedOwnerGraph(?int $userId, ?int $unregisteredCustomerId, int $base): array
    {
        $ids = [
            'pet' => $base + 1,
            'walkin' => $base + 2,
            'booking' => $base + 3,
            'booking_pet' => $base + 4,
            'appointment' => $base + 5,
            'concern' => $base + 6,
            'response' => $base + 7,
            'referral' => $base + 8,
            'review' => $base + 9,
            'record' => $base + 10,
            'attachment' => $base + 11,
            'vital' => $base + 12,
            'medication' => $base + 13,
            'vaccination' => $base + 14,
            'booking_service' => $base + 15,
            'payment' => $base + 16,
            'notification' => $base + 17,
            'customer_notification' => $base + 18,
            'attachment_path' => "clinic/attachments/{$base}/record.pdf",
        ];

        DB::table('pets')->insert([
            'pet_id' => $ids['pet'],
            'user_id' => $userId,
            'unregistered_customer_id' => $unregisteredCustomerId,
        ]);
        DB::table('walkins')->insert([
            'id' => $ids['walkin'],
            'user_id' => $userId,
            'unregistered_customer_id' => $unregisteredCustomerId,
        ]);
        DB::table('bookings')->insert([
            'booking_id' => $ids['booking'],
            'user_id' => $userId,
            'walkin_id' => $ids['walkin'],
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => $ids['booking_pet'],
            'booking_id' => $ids['booking'],
            'pet_id' => $ids['pet'],
        ]);
        DB::table('clinic_appointments')->insert([
            'id' => $ids['appointment'],
            'user_id' => $userId,
            'walkin_id' => $ids['walkin'],
            'pet_id' => $ids['pet'],
        ]);
        DB::table('grooming_medical_concerns')->insert([
            'id' => $ids['concern'],
            'booking_id' => $ids['booking'],
            'booking_pet_id' => $ids['booking_pet'],
            'pet_id' => $ids['pet'],
            'clinic_appointment_id' => $ids['appointment'],
        ]);
        DB::table('grooming_medical_concern_responses')->insert([
            'id' => $ids['response'],
            'concern_id' => $ids['concern'],
        ]);
        DB::table('grooming_clinic_referrals')->insert([
            'id' => $ids['referral'],
            'grooming_medical_concern_id' => $ids['concern'],
            'booking_id' => $ids['booking'],
            'booking_pet_id' => $ids['booking_pet'],
            'pet_id' => $ids['pet'],
            'clinic_appointment_id' => $ids['appointment'],
            'owner_user_id_at_referral' => $userId,
        ]);
        DB::table('grooming_stopped_payment_reviews')->insert([
            'id' => $ids['review'],
            'booking_id' => $ids['booking'],
            'booking_pet_id' => $ids['booking_pet'],
            'pet_id' => $ids['pet'],
            'grooming_medical_concern_id' => $ids['concern'],
        ]);
        DB::table('clinic_records')->insert([
            'id' => $ids['record'],
            'clinic_appointment_id' => $ids['appointment'],
        ]);
        DB::table('clinic_attachments')->insert([
            'id' => $ids['attachment'],
            'clinic_record_id' => $ids['record'],
            'file_path' => $ids['attachment_path'],
        ]);
        DB::table('clinic_vitals')->insert([
            'id' => $ids['vital'],
            'clinic_appointment_id' => $ids['appointment'],
        ]);
        DB::table('clinic_medications')->insert([
            'id' => $ids['medication'],
            'clinic_record_id' => $ids['record'],
        ]);
        DB::table('vaccination_records')->insert([
            'id' => $ids['vaccination'],
            'pet_id' => $ids['pet'],
            'clinic_appointment_id' => $ids['appointment'],
        ]);
        DB::table('booking_services')->insert([
            'id' => $ids['booking_service'],
            'booking_id' => $ids['booking'],
        ]);
        DB::table('payments')->insert([
            'id' => $ids['payment'],
            'booking_id' => $ids['booking'],
        ]);
        DB::table('notifications')->insert([
            'id' => $ids['notification'],
            'booking_id' => $ids['booking'],
        ]);
        DB::table('customer_notifications')->insert([
            'id' => $ids['customer_notification'],
            'user_id' => $userId,
            'booking_id' => $ids['booking'],
            'pet_id' => $ids['pet'],
            'grooming_medical_concern_id' => $ids['concern'],
            'grooming_clinic_referral_id' => $ids['referral'],
        ]);

        Storage::disk('local')->put($ids['attachment_path'], "owner {$base}");

        return $ids;
    }

    /** @param array<string, int|string> $ids */
    private function assertGraphStillExists(array $ids): void
    {
        foreach ($this->graphDatabaseKeys($ids) as $table => $key) {
            $this->assertDatabaseHas($table, $key);
        }
    }

    /**
     * @param  array<string, int|string>  $ids
     * @return array<string, array<string, int|string>>
     */
    private function graphDatabaseKeys(array $ids): array
    {
        return [
            'pets' => ['pet_id' => $ids['pet']],
            'walkins' => ['id' => $ids['walkin']],
            'bookings' => ['booking_id' => $ids['booking']],
            'booking_pets' => ['booking_pet_id' => $ids['booking_pet']],
            'clinic_appointments' => ['id' => $ids['appointment']],
            'grooming_medical_concerns' => ['id' => $ids['concern']],
            'grooming_medical_concern_responses' => ['id' => $ids['response']],
            'grooming_clinic_referrals' => ['id' => $ids['referral']],
            'grooming_stopped_payment_reviews' => ['id' => $ids['review']],
            'clinic_records' => ['id' => $ids['record']],
            'clinic_attachments' => ['id' => $ids['attachment']],
            'clinic_vitals' => ['id' => $ids['vital']],
            'clinic_medications' => ['id' => $ids['medication']],
            'vaccination_records' => ['id' => $ids['vaccination']],
            'booking_services' => ['id' => $ids['booking_service']],
            'payments' => ['id' => $ids['payment']],
            'notifications' => ['id' => $ids['notification']],
            'customer_notifications' => ['id' => $ids['customer_notification']],
        ];
    }

    private function authenticateAsStaff(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('profile_photo')->nullable();
        });
        Schema::create('unregistered_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
        });
        Schema::create('pets', function (Blueprint $table): void {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
        });
        Schema::create('walkins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
        });
        Schema::create('bookings', function (Blueprint $table): void {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $table): void {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
        });
        Schema::create('booking_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('booking_id');
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('booking_id');
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('booking_id');
        });
        Schema::create('customer_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->unsignedBigInteger('grooming_medical_concern_id')->nullable();
            $table->unsignedBigInteger('grooming_clinic_referral_id')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
        });
        Schema::create('clinic_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
        });
        Schema::create('clinic_vitals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
        });
        Schema::create('clinic_medications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
        });
        Schema::create('clinic_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('file_path');
        });
        Schema::create('vaccination_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
        });
        Schema::create('grooming_medical_concern_responses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('concern_id');
        });
        Schema::create('grooming_clinic_referrals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grooming_medical_concern_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->unsignedInteger('owner_user_id_at_referral')->nullable();
        });
        Schema::create('grooming_stopped_payment_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('grooming_medical_concern_id');
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('pending_customer_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
        });
    }
}
