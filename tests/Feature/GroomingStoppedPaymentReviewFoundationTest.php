<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingStoppedPaymentReview;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class GroomingStoppedPaymentReviewFoundationTest extends TestCase
{
    private $migration;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        $this->createExistingSchema();
        $this->insertExistingData();

        $this->migration = require base_path(
            'database/migrations/2026_08_01_000001_create_grooming_stopped_payment_reviews_table.php',
        );
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('grooming_stopped_payment_reviews');
        Schema::dropIfExists('grooming_medical_concerns');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_sqlite_migration_creates_the_required_portable_schema(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasColumns('grooming_stopped_payment_reviews', [
            'id',
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'grooming_medical_concern_id',
            'decision',
            'original_pet_subtotal',
            'final_pet_charge',
            'internal_reason',
            'customer_explanation',
            'reviewed_by_user_id',
            'reviewed_by_name',
            'reviewed_at',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn(
            'grooming_stopped_payment_reviews',
            'updated_at',
        ));
        $this->assertFalse(Schema::hasColumn(
            'grooming_stopped_payment_reviews',
            'deleted_at',
        ));

        $decisionColumn = collect(DB::select(
            "PRAGMA table_info('grooming_stopped_payment_reviews')",
        ))->firstWhere('name', 'decision');
        $this->assertNotNull($decisionColumn);
        $this->assertSame('varchar', strtolower($decisionColumn->type));
        $this->assertStringNotContainsString('enum', strtolower($decisionColumn->type));

        $this->assertIndexesExist('grooming_stopped_payment_reviews', [
            'gspr_booking_pet_uq',
            'gspr_concern_uq',
            'gspr_booking_reviewed_idx',
            'gspr_pet_reviewed_idx',
            'gspr_reviewer_reviewed_idx',
            'gspr_decision_idx',
        ]);
        $this->assertIndexesExist('grooming_medical_concerns', [
            'gmc_review_context_uq',
        ]);

        $this->assertSqliteCompositeForeignKey(
            'grooming_stopped_payment_reviews',
            ['booking_id'],
            'bookings',
            ['booking_id'],
            'RESTRICT',
        );
        $this->assertSqliteCompositeForeignKey(
            'grooming_stopped_payment_reviews',
            ['pet_id'],
            'pets',
            ['pet_id'],
            'RESTRICT',
        );
        $this->assertSqliteCompositeForeignKey(
            'grooming_stopped_payment_reviews',
            ['booking_pet_id', 'booking_id', 'pet_id'],
            'booking_pets',
            ['booking_pet_id', 'booking_id', 'pet_id'],
            'RESTRICT',
        );
        $this->assertSqliteCompositeForeignKey(
            'grooming_stopped_payment_reviews',
            [
                'grooming_medical_concern_id',
                'booking_id',
                'booking_pet_id',
                'pet_id',
            ],
            'grooming_medical_concerns',
            ['id', 'booking_id', 'booking_pet_id', 'pet_id'],
            'RESTRICT',
        );
        $this->assertSqliteCompositeForeignKey(
            'grooming_stopped_payment_reviews',
            ['reviewed_by_user_id'],
            'users',
            ['user_id'],
            'SET NULL',
        );
    }

    public function test_migration_rolls_back_its_table_and_supporting_index(): void
    {
        $this->migration->down();

        $this->assertFalse(Schema::hasTable('grooming_stopped_payment_reviews'));
        $this->assertFalse(
            collect(Schema::getIndexes('grooming_medical_concerns'))
                ->pluck('name')
                ->contains('gmc_review_context_uq'),
        );

        $this->migration->up();
        $this->assertTrue(Schema::hasTable('grooming_stopped_payment_reviews'));
    }

    public function test_exact_context_is_accepted_and_mismatches_are_rejected(): void
    {
        $valid = $this->createReview();
        $this->assertSame(301, $valid->booking_pet_id);

        DB::table('grooming_stopped_payment_reviews')
            ->where('id', $valid->id)
            ->delete();

        $this->assertReviewInsertFails([
            'booking_id' => 202,
        ]);
        $this->assertReviewInsertFails([
            'pet_id' => 102,
        ]);
        $this->assertReviewInsertFails([
            'grooming_medical_concern_id' => 402,
        ]);
    }

    public function test_one_review_per_booking_pet_and_concern_allows_sibling_reviews(): void
    {
        $first = $this->createReview();

        $this->assertReviewInsertFails([
            'grooming_medical_concern_id' => 404,
        ]);
        $this->assertReviewInsertFails([
            'decision' => GroomingStoppedPaymentReview::DECISION_NO_CHARGE,
        ]);

        $second = $this->createReview([
            'booking_pet_id' => 302,
            'pet_id' => 102,
            'grooming_medical_concern_id' => 402,
            'decision' => GroomingStoppedPaymentReview::DECISION_NO_CHARGE,
            'original_pet_subtotal' => '80.00',
            'final_pet_charge' => '0.00',
        ]);

        $this->assertSame($first->booking_id, $second->booking_id);
        $this->assertNotSame($first->booking_pet_id, $second->booking_pet_id);
        $this->assertSame(2, GroomingStoppedPaymentReview::count());
    }

    public function test_linked_context_deletion_is_restricted_and_reviewer_is_historically_safe(): void
    {
        $review = $this->createReview();

        DB::table('users')->where('user_id', 2)->delete();
        $review->refresh();

        $this->assertNull($review->reviewed_by_user_id);
        $this->assertSame('Historical Reviewer', $review->reviewed_by_name);

        $this->assertDeleteFails('grooming_medical_concerns', 'id', 401);
        $this->assertDeleteFails('booking_pets', 'booking_pet_id', 301);
        $this->assertDeleteFails('bookings', 'booking_id', 201);
        $this->assertDeleteFails('pets', 'pet_id', 101);
    }

    public function test_amounts_relationships_constants_helpers_and_immutability(): void
    {
        $review = $this->createReview([
            'original_pet_subtotal' => '100.00',
            'final_pet_charge' => '64.25',
        ]);

        $this->assertSame('100.00', $review->original_pet_subtotal);
        $this->assertSame('64.25', $review->final_pet_charge);
        $this->assertSame('35.75', $review->adjustmentAmount());
        $this->assertTrue(GroomingStoppedPaymentReview::isValidDecision('partial_charge'));
        $this->assertFalse(GroomingStoppedPaymentReview::isValidDecision('discount'));
        $this->assertSame(
            'Partial charge',
            GroomingStoppedPaymentReview::decisionLabel('partial_charge'),
        );
        $this->assertSame(
            'Unknown decision',
            GroomingStoppedPaymentReview::decisionLabel('not_known'),
        );
        $this->assertNotNull($review->reviewed_at);
        $this->assertNotNull($review->created_at);

        $this->assertSame(201, $review->booking->booking_id);
        $this->assertSame(301, $review->bookingPet->booking_pet_id);
        $this->assertSame(101, $review->pet->pet_id);
        $this->assertSame(401, $review->groomingMedicalConcern->id);
        $this->assertSame(2, $review->reviewedBy->user_id);
        $this->assertTrue(Booking::findOrFail(201)->groomingStoppedPaymentReviews->contains($review));
        $this->assertTrue(BookingPet::findOrFail(301)->groomingStoppedPaymentReview->is($review));
        $this->assertTrue(Pet::findOrFail(101)->groomingStoppedPaymentReviews->contains($review));
        $this->assertTrue(GroomingMedicalConcern::findOrFail(401)->stoppedPaymentReview->is($review));
        $this->assertTrue(User::findOrFail(2)->groomingStoppedPaymentReviews->contains($review));

        try {
            $review->internal_reason = 'Changed reason';
            $review->save();
            $this->fail('An immutable review should not be updateable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('Service stopped for safety.', $review->fresh()->internal_reason);

        try {
            $review->delete();
            $this->fail('An immutable review should not be deleteable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseHas('grooming_stopped_payment_reviews', [
            'id' => $review->id,
        ]);
    }

    public function test_zero_amount_is_preserved_separately_from_the_original_subtotal(): void
    {
        $review = $this->createReview([
            'decision' => GroomingStoppedPaymentReview::DECISION_NO_CHARGE,
            'original_pet_subtotal' => '75.50',
            'final_pet_charge' => '0.00',
        ]);

        $this->assertSame('75.50', $review->original_pet_subtotal);
        $this->assertSame('0.00', $review->final_pet_charge);
        $this->assertSame('75.50', $review->adjustmentAmount());
    }

    public function test_stopped_payment_reviews_have_no_update_or_delete_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains(
                $route->uri(),
                'stopped-payment-review',
            ));

        $this->assertCount(2, $routes);
        $this->assertSame(
            ['GET', 'HEAD', 'POST'],
            $routes->flatMap(fn ($route) => $route->methods())
                ->unique()
                ->sort()
                ->values()
                ->all(),
        );
    }

    private function createExistingSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('booking_reference');
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->cascadeOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
            $table->unique(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'bp_concern_identity_uq',
            );
        });

        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->string('applied_grooming_action', 40)->nullable();
            $table->timestamps();
            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gmc_booking_pet_fk',
            )
                ->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    private function insertExistingData(): void
    {
        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Customer',
                'last_name' => 'Owner',
                'role' => 'customer',
            ],
            [
                'user_id' => 2,
                'first_name' => 'Historical',
                'last_name' => 'Reviewer',
                'role' => 'staff',
            ],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 1, 'pet_name' => 'Alpha'],
            ['pet_id' => 102, 'user_id' => 1, 'pet_name' => 'Beta'],
        ]);
        DB::table('bookings')->insert([
            ['booking_id' => 201, 'user_id' => 1, 'booking_reference' => 'BK-201'],
            ['booking_id' => 202, 'user_id' => 1, 'booking_reference' => 'BK-202'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 301, 'booking_id' => 201, 'pet_id' => 101],
            ['booking_pet_id' => 302, 'booking_id' => 201, 'pet_id' => 102],
            ['booking_pet_id' => 303, 'booking_id' => 202, 'pet_id' => 101],
        ]);
        DB::table('grooming_medical_concerns')->insert([
            [
                'id' => 401,
                'public_id' => '11111111-1111-4111-8111-111111111111',
                'booking_id' => 201,
                'booking_pet_id' => 301,
                'pet_id' => 101,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'created_at' => '2026-08-01 09:00:00',
                'updated_at' => '2026-08-01 09:00:00',
            ],
            [
                'id' => 402,
                'public_id' => '22222222-2222-4222-8222-222222222222',
                'booking_id' => 201,
                'booking_pet_id' => 302,
                'pet_id' => 102,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'created_at' => '2026-08-01 09:05:00',
                'updated_at' => '2026-08-01 09:05:00',
            ],
            [
                'id' => 403,
                'public_id' => '33333333-3333-4333-8333-333333333333',
                'booking_id' => 202,
                'booking_pet_id' => 303,
                'pet_id' => 101,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'created_at' => '2026-08-01 09:10:00',
                'updated_at' => '2026-08-01 09:10:00',
            ],
            [
                'id' => 404,
                'public_id' => '44444444-4444-4444-8444-444444444444',
                'booking_id' => 201,
                'booking_pet_id' => 301,
                'pet_id' => 101,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'created_at' => '2026-08-01 09:15:00',
                'updated_at' => '2026-08-01 09:15:00',
            ],
        ]);
    }

    private function createReview(array $overrides = []): GroomingStoppedPaymentReview
    {
        return GroomingStoppedPaymentReview::create(array_merge([
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'grooming_medical_concern_id' => 401,
            'decision' => GroomingStoppedPaymentReview::DECISION_PARTIAL_CHARGE,
            'original_pet_subtotal' => '100.00',
            'final_pet_charge' => '60.00',
            'internal_reason' => 'Service stopped for safety.',
            'customer_explanation' => 'The final charge reflects completed work.',
            'reviewed_by_user_id' => 2,
            'reviewed_by_name' => 'Historical Reviewer',
            'reviewed_at' => '2026-08-01 10:00:00',
        ], $overrides));
    }

    private function assertReviewInsertFails(array $overrides): void
    {
        $record = array_merge([
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'grooming_medical_concern_id' => 401,
            'decision' => 'partial_charge',
            'original_pet_subtotal' => '100.00',
            'final_pet_charge' => '50.00',
            'internal_reason' => 'Invalid review context.',
            'customer_explanation' => 'Invalid review context.',
            'reviewed_by_user_id' => 2,
            'reviewed_by_name' => 'Historical Reviewer',
            'reviewed_at' => '2026-08-01 10:30:00',
            'created_at' => '2026-08-01 10:30:00',
        ], $overrides);

        try {
            DB::table('grooming_stopped_payment_reviews')->insert($record);
            $this->fail('The invalid stopped-grooming payment review should be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertDeleteFails(string $table, string $key, int $value): void
    {
        try {
            DB::table($table)->where($key, $value)->delete();
            $this->fail("Deleting the linked {$table} row should have been restricted.");
        } catch (QueryException) {
            $this->assertDatabaseHas($table, [$key => $value]);
        }
    }

    private function assertIndexesExist(string $table, array $expectedNames): void
    {
        $indexNames = collect(Schema::getIndexes($table))->pluck('name');

        foreach ($expectedNames as $expectedName) {
            $this->assertTrue(
                $indexNames->contains($expectedName),
                "Missing index {$expectedName} on {$table}.",
            );
        }
    }

    private function assertSqliteCompositeForeignKey(
        string $table,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $deleteRule,
    ): void {
        $groups = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
            ->groupBy('id');

        $matching = $groups->first(function ($rows) use (
            $columns,
            $referencedTable,
            $referencedColumns,
            $deleteRule,
        ) {
            $rows = $rows->sortBy('seq')->values();

            return $rows->first()->table === $referencedTable
                && $rows->pluck('from')->all() === $columns
                && $rows->pluck('to')->all() === $referencedColumns
                && $rows->pluck('on_delete')->unique()->values()->all() === [$deleteRule];
        });

        $this->assertNotNull(
            $matching,
            'Missing expected composite foreign key on '.$table.'.',
        );
    }
}
