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
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'is_popular')) {
                $table->boolean('is_popular')->default(false)->after('is_free');
            }
            if (!Schema::hasColumn('plans', 'cta_text')) {
                $table->string('cta_text')->nullable()->after('is_popular');
            }
            if (!Schema::hasColumn('plans', 'badge_text')) {
                $table->string('badge_text')->nullable()->after('cta_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_popular', 'cta_text', 'badge_text']);
        });
    }
};
