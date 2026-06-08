<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make campaign_id nullable (was NOT NULL) — many AI calls are not campaign-scoped
        DB::statement('ALTER TABLE ai_costs MODIFY campaign_id BIGINT UNSIGNED NULL');

        Schema::table('ai_costs', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('campaign_id')
                ->constrained()->nullOnDelete();
            $table->string('operation')->default('generateContent')->after('service');
            $table->unsignedInteger('input_tokens')->default(0)->after('model');
            $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
            $table->unsignedInteger('cached_tokens')->default(0)->after('output_tokens');
            $table->unsignedInteger('duration_ms')->nullable()->after('cost');
            $table->string('task_type')->nullable()->after('duration_ms');
            $table->json('metadata')->nullable()->after('task_type');

            $table->index(['customer_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
            $table->index('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_costs', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['campaign_id', 'created_at']);
            $table->dropIndex(['model']);
            $table->dropForeign(['customer_id']);
            $table->dropColumn([
                'customer_id', 'operation', 'input_tokens', 'output_tokens',
                'cached_tokens', 'duration_ms', 'task_type', 'metadata',
            ]);
        });

        DB::statement('ALTER TABLE ai_costs MODIFY campaign_id BIGINT UNSIGNED NOT NULL');
    }
};
