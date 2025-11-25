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
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->string('gemini_video_uri')->nullable()->after('cloudfront_url')->comment('Gemini video URI for potential extensions');
            $table->unsignedBigInteger('parent_video_id')->nullable()->after('gemini_video_uri')->comment('ID of the source video if this is an extension');
            $table->integer('extension_count')->default(0)->after('parent_video_id')->comment('Number of times this video has been extended');
            
            // Add foreign key constraint
            $table->foreign('parent_video_id')->references('id')->on('video_collaterals')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropForeign(['parent_video_id']);
            $table->dropColumn(['gemini_video_uri', 'parent_video_id', 'extension_count']);
        });
    }
};
