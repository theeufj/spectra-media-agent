<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a step opt into rich HTML while leaving the rest plain.
 *
 * Per-step rather than per-sequence, and defaulting to plain, because the two
 * existing chains are deliberately plain-text founder emails — a follow-up
 * that arrives looking like a marketing template contradicts the thing it is
 * trying to say. This makes formatting available without making it the
 * default, so nothing already written changes appearance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_sequence_steps', function (Blueprint $table) {
            $table->string('format', 10)->default('plain')->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('email_sequence_steps', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
