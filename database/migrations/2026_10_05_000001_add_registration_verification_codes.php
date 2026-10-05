<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing link tokens are retained only for already-issued links.
        // MySQL may retain earlier ALTERs when a later statement fails.
        foreach (['pending_customer_registrations', 'users'] as $name) {
            $columns = Schema::getColumnListing($name);
            Schema::table($name, function (Blueprint $table) use ($columns) {
                if (! in_array('email_verification_code_hash', $columns, true)) {
                    $table->string('email_verification_code_hash')->nullable();
                }
                if (! in_array('email_verification_attempts', $columns, true)) {
                    $table->unsignedTinyInteger('email_verification_attempts')->default(0);
                }
                if (! in_array('email_verification_last_sent_at', $columns, true)) {
                    $table->timestamp('email_verification_last_sent_at')->nullable();
                }
            });
        }
        if (! Schema::hasColumn('pending_customer_registrations', 'registration_edit_token_hash')) {
            Schema::table('pending_customer_registrations', function (Blueprint $table) {
                $table->string('registration_edit_token_hash', 64)->nullable();
            });
        }
        if (! Schema::hasIndex('pending_customer_registrations', ['registration_edit_token_hash'], 'unique')) {
            Schema::table('pending_customer_registrations', function (Blueprint $table) {
                $table->unique('registration_edit_token_hash', 'pending_registration_edit_token_unique');
            });
        }
        if (! Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('phone_verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['pending_customer_registrations', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['email_verification_code_hash', 'email_verification_attempts', 'email_verification_last_sent_at']);
            });
        }
        if (Schema::hasIndex('pending_customer_registrations', 'pending_registration_edit_token_unique')) {
            Schema::table('pending_customer_registrations', function (Blueprint $table) {
                $table->dropUnique('pending_registration_edit_token_unique');
            });
        }
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->dropColumn('registration_edit_token_hash');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
