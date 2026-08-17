<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a deleted customer be recovered.
 *
 * Deletion was a hard delete, and the observer's deleted() hook was empty. The
 * campaigns on Google and Facebook carried on running and spending, while the
 * rows that recorded which campaigns those were had just been destroyed — so the
 * one action that most needed to stop the money instead removed the means to
 * stop it.
 *
 * Soft deletes keep the trail. Stopping the spend is handled separately, before
 * the record goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
