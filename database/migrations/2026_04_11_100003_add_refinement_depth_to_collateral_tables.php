<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->unsignedInteger('refinement_depth')->default(0)->after('parent_id');
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->unsignedInteger('refinement_depth')->default(0)->after('parent_video_id');
        });
    }

    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->dropColumn('refinement_depth');
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropColumn('refinement_depth');
        });
    }
};
