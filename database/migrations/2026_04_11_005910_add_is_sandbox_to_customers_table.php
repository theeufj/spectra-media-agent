<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->after('tracking_signing_secret');
            $table->json('sandbox_results')->nullable()->after('is_sandbox');
            $table->timestamp('sandbox_expires_at')->nullable()->after('sandbox_results');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['is_sandbox', 'sandbox_results', 'sandbox_expires_at']);
        });
    }
};
