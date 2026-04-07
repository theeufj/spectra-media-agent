<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->json('content_analysis')->nullable()->after('performance_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->dropColumn('content_analysis');
        });
    }
};
