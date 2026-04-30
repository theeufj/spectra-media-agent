<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('account_health_score')->nullable()->after('is_sandbox');
            $table->timestamp('health_score_updated_at')->nullable()->after('account_health_score');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['account_health_score', 'health_score_updated_at']);
        });
    }
};
