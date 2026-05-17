<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            // Tracks which provider generated this video: veo | vidu | runway
            // null = legacy records generated before this column existed (treat as veo)
            $table->string('provider')->nullable()->default(null)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
