<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Starter-plan users can run ads on one platform (google or facebook).
            // Null means google (the default).
            $table->string('starter_platform')->nullable()->default(null)->after('assigned_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('starter_platform');
        });
    }
};
