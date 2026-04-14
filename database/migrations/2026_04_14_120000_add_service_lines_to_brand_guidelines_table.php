<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->json('service_lines')->nullable()->after('do_not_use');
        });
    }

    public function down(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->dropColumn('service_lines');
        });
    }
};
