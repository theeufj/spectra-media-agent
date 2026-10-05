<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // Historical proposals were requested in USD. Freeze that meaning.
            $table->char('currency_code', 3)->default('USD');
            $table->string('generation_step')->default('queued');
            $table->timestamp('generation_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn(['currency_code', 'generation_step', 'generation_started_at']));
    }
};
