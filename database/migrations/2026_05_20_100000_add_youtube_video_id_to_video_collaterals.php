<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->string('youtube_video_id')->nullable()->after('s3_path');
        });
    }

    public function down(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropColumn('youtube_video_id');
        });
    }
};
