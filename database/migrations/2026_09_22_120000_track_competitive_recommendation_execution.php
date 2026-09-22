<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('source')->nullable()->index();
            $table->string('fingerprint', 64)->nullable()->unique();
            $table->json('evidence')->nullable();
            $table->json('payload')->nullable();
            $table->json('execution')->nullable();
            $table->json('outcome')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('measured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'fingerprint', 'evidence', 'payload', 'execution', 'outcome', 'applied_at', 'verified_at', 'measured_at']);
        });
    }
};
