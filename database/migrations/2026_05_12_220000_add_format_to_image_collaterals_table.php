<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->string('format', 20)->default('square')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
