<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('bookings')) {
            return;
        }

        if (!Schema::hasColumn('bookings', 'booking_reference')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('booking_reference', 32)->nullable()->after('booking_id');
            });
        }

        $rows = DB::table('bookings')
            ->select(['booking_id', 'booking_date', 'booking_reference'])
            ->orderBy('booking_id')
            ->get();

        foreach ($rows as $row) {
            if (is_string($row->booking_reference) && trim($row->booking_reference) !== '') {
                continue;
            }

            $date = $row->booking_date ? date('Ymd', strtotime((string) $row->booking_date)) : now()->format('Ymd');
            $reference = sprintf('GRM-%s-%06d', $date, (int) $row->booking_id);

            DB::table('bookings')
                ->where('booking_id', $row->booking_id)
                ->update([
                    'booking_reference' => $reference,
                ]);
        }

        if (!$this->hasIndex('bookings', 'bookings_booking_reference_unique')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unique('booking_reference');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('bookings') || !Schema::hasColumn('bookings', 'booking_reference')) {
            return;
        }

        if ($this->hasIndex('bookings', 'bookings_booking_reference_unique')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('bookings_booking_reference_unique');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('booking_reference');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($result);
    }
};
