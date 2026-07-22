<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPetMedicalRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->string('appointment_type')->default('pre_registered');
            $table->string('status');
            $table->date('appointment_date');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();
            $table->dateTime('consultation_finished_at')->nullable();
        });

        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('findings')->nullable();
            $table->text('treatment_given')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->text('vet_notes')->nullable();
        });

        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('body_condition_score')->nullable();
        });

        Schema::create('clinic_medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('drug_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
        });

        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('label')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_attachments');
        Schema::dropIfExists('clinic_medications');
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');

        parent::tearDown();
    }

    public function test_medical_records_require_authentication(): void
    {
        $this->getJson('/api/pets/101/medical-records')->assertUnauthorized();
    }

    public function test_customer_can_retrieve_only_their_own_pet_and_foreign_and_missing_pets_are_indistinguishable(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');
        $this->insertPet(202, 20, 'Not My Pet');
        $this->insertAppointment(1, 101, 'completed', 'CL-OWN-001', '2026-07-20');

        $this->getJson('/api/pets/101/medical-records')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'medical_records')
            ->assertJsonPath('medical_records.0.appointment_reference', 'CL-OWN-001');

        $foreign = $this->getJson('/api/pets/202/medical-records')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $missing = $this->getJson('/api/pets/999/medical-records')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $this->assertSame($foreign->getContent(), $missing->getContent());
    }

    public function test_medical_records_are_isolated_by_pet_owner_and_medications_stay_with_their_visit(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');
        $this->insertPet(102, 10, 'Bruno');
        $this->insertPet(202, 20, 'Other Owner Pet');

        $mochiNewRecord = $this->insertAppointment(11, 101, 'completed', 'CL-MOCHI-NEW', '2026-07-22');
        $mochiOldRecord = $this->insertAppointment(12, 101, 'completed', 'CL-MOCHI-OLD', '2026-06-10');
        $brunoRecord = $this->insertAppointment(13, 102, 'completed', 'CL-BRUNO', '2026-07-21');
        $otherOwnerRecord = $this->insertAppointment(14, 202, 'completed', 'CL-OTHER', '2026-07-23');

        $this->insertMedication($mochiNewRecord, 'Mochi New Medicine');
        $this->insertMedication($mochiOldRecord, 'Mochi Old Medicine');
        $this->insertMedication($brunoRecord, 'Bruno Medicine');
        $this->insertMedication($otherOwnerRecord, 'Other Owner Medicine');

        $response = $this->getJson('/api/pets/101/medical-records')
            ->assertOk()
            ->assertJsonCount(2, 'medical_records')
            ->assertJsonPath('medical_records.0.appointment_reference', 'CL-MOCHI-NEW')
            ->assertJsonPath('medical_records.0.medications.0.drug_name', 'Mochi New Medicine')
            ->assertJsonPath('medical_records.1.appointment_reference', 'CL-MOCHI-OLD')
            ->assertJsonPath('medical_records.1.medications.0.drug_name', 'Mochi Old Medicine');

        $response
            ->assertJsonMissing(['appointment_reference' => 'CL-BRUNO'])
            ->assertJsonMissing(['appointment_reference' => 'CL-OTHER'])
            ->assertJsonMissing(['drug_name' => 'Bruno Medicine'])
            ->assertJsonMissing(['drug_name' => 'Other Owner Medicine']);
    }

    public function test_only_completed_appointments_with_a_medical_record_are_returned(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');

        $statuses = [
            'waiting_to_arrive',
            'checked_in',
            'in_consultation',
            'for_payment',
            'completed',
            'cancelled',
            'no_show',
        ];

        foreach ($statuses as $index => $status) {
            $this->insertAppointment(
                $index + 1,
                101,
                $status,
                'CL-'.strtoupper($status),
                '2026-07-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            );
        }

        $this->insertAppointment(99, 101, 'completed', 'CL-COMPLETED-NO-RECORD', '2026-07-30', false);

        $response = $this->getJson('/api/pets/101/medical-records')
            ->assertOk()
            ->assertJsonCount(1, 'medical_records')
            ->assertJsonPath('medical_records.0.appointment_reference', 'CL-COMPLETED')
            ->assertJsonPath('medical_records.0.status', 'completed');

        foreach (array_diff($statuses, ['completed']) as $status) {
            $response->assertJsonMissing(['appointment_reference' => 'CL-'.strtoupper($status)]);
        }

        $response->assertJsonMissing(['appointment_reference' => 'CL-COMPLETED-NO-RECORD']);
    }

    public function test_response_uses_an_exact_client_safe_whitelist_and_excludes_attachments(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');
        $recordId = $this->insertAppointment(1, 101, 'completed', 'CL-SAFE-001', '2026-07-22', true, [
            'appointment_notes' => 'PRIVATE APPOINTMENT NOTES',
            'findings' => 'INTERNAL FINDINGS',
            'vet_notes' => 'PRIVATE VET NOTES',
        ]);

        DB::table('clinic_vitals')->insert([
            'clinic_appointment_id' => 1,
            'weight_kg' => 4.25,
            'temperature_c' => 38.4,
            'heart_rate_bpm' => 110,
            'respiratory_rate_bpm' => 24,
            'body_condition_score' => 5,
        ]);
        $this->insertMedication($recordId, 'Amoxicillin');
        DB::table('clinic_attachments')->insert([
            'clinic_record_id' => $recordId,
            'file_name' => 'PRIVATE-LAB-RESULT.pdf',
            'file_path' => 'clinic/attachments/1/private.pdf',
            'file_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'label' => 'PRIVATE ATTACHMENT LABEL',
            'created_at' => '2026-07-22 10:00:00',
        ]);

        $response = $this->getJson('/api/pets/101/medical-records')->assertOk();
        $visit = $response->json('medical_records.0');

        $this->assertSame([
            'appointment_reference',
            'appointment_date',
            'status',
            'chief_complaint',
            'diagnosis',
            'treatment_given',
            'follow_up_date',
            'follow_up_notes',
            'vitals',
            'medications',
        ], array_keys($visit));
        $this->assertSame([
            'weight_kg',
            'temperature_c',
            'heart_rate_bpm',
            'respiratory_rate_bpm',
            'body_condition_score',
        ], array_keys($visit['vitals']));
        $this->assertSame([
            'drug_name',
            'dosage',
            'frequency',
            'duration',
            'instructions',
        ], array_keys($visit['medications'][0]));
        foreach ([
            'vet_notes',
            'findings',
            'notes',
            'user_id',
            'pet_id',
            'clinic_appointment_id',
            'clinic_record_id',
            'total_amount',
            'paid',
            'attachments',
        ] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $visit);
        }

        $encoded = json_encode($response->json());
        foreach ([
            'vet_notes',
            'findings',
            'user_id',
            'pet_id',
            'clinic_appointment_id',
            'clinic_record_id',
            'total_amount',
            'paid',
            'attachments',
            'file_name',
            'file_path',
            'download_endpoint',
            'PRIVATE APPOINTMENT NOTES',
            'INTERNAL FINDINGS',
            'PRIVATE VET NOTES',
            'PRIVATE-LAB-RESULT.pdf',
            'PRIVATE ATTACHMENT LABEL',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $encoded);
        }
    }

    private function authenticateCustomer(int $userId): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => 'customer',
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function insertPet(int $petId, int $userId, string $petName): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'user_id' => $userId,
            'pet_name' => $petName,
            'species' => 'cat',
        ]);
    }

    private function insertAppointment(
        int $appointmentId,
        int $petId,
        string $status,
        string $reference,
        string $date,
        bool $withRecord = true,
        array $sensitive = [],
    ): ?int {
        DB::table('clinic_appointments')->insert([
            'id' => $appointmentId,
            'appointment_reference' => $reference,
            'appointment_type' => 'pre_registered',
            'status' => $status,
            'appointment_date' => $date,
            'user_id' => 9999,
            'pet_id' => $petId,
            'chief_complaint' => 'Appointment-level complaint',
            'total_amount' => 1500,
            'paid' => $status === 'completed',
            'notes' => $sensitive['appointment_notes'] ?? null,
            'consultation_finished_at' => $status === 'completed' ? "{$date} 11:00:00" : null,
        ]);

        if (! $withRecord) {
            return null;
        }

        return DB::table('clinic_records')->insertGetId([
            'clinic_appointment_id' => $appointmentId,
            'chief_complaint' => "Reason for {$reference}",
            'diagnosis' => "Diagnosis for {$reference}",
            'findings' => $sensitive['findings'] ?? 'Internal examination findings',
            'treatment_given' => "Treatment for {$reference}",
            'follow_up_date' => '2026-08-01',
            'follow_up_notes' => 'Continue care at home.',
            'vet_notes' => $sensitive['vet_notes'] ?? 'Internal veterinarian note',
        ]);
    }

    private function insertMedication(int $recordId, string $drugName): void
    {
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $recordId,
            'drug_name' => $drugName,
            'dosage' => '5 ml',
            'frequency' => 'Twice daily',
            'duration' => '7 days',
            'instructions' => 'Give after meals.',
        ]);
    }
}
