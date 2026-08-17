<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor enrolment for admin accounts.
 *
 * A stolen admin session had unlimited, unaudited reach over billing, customer
 * records and the MCC credentials the whole platform authenticates with. There
 * was no second factor and, until now, no rate limit either.
 *
 * The secret and recovery codes are encrypted at rest: a database dump should
 * not hand over the second factor along with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
