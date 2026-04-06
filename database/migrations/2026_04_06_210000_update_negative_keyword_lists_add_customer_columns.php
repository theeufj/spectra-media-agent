<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('negative_keyword_lists', function (Blueprint $table) {
            if (!Schema::hasColumn('negative_keyword_lists', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
                $table->index('customer_id');
            }
            if (!Schema::hasColumn('negative_keyword_lists', 'keywords')) {
                $table->json('keywords')->default('[]');
            }
            if (!Schema::hasColumn('negative_keyword_lists', 'applied_to_campaigns')) {
                $table->json('applied_to_campaigns')->default('[]');
            }
            if (!Schema::hasColumn('negative_keyword_lists', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('negative_keyword_lists', function (Blueprint $table) {
            $columns = [];
            foreach (['customer_id', 'keywords', 'applied_to_campaigns', 'created_by'] as $col) {
                if (Schema::hasColumn('negative_keyword_lists', $col)) {
                    $columns[] = $col;
                }
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
