<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a video's audio was replaced with the single-voice TTS narration.
     * Guards FinalizeVideoNarration from re-processing the same clip.
     */
    public function up(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->timestamp('narration_finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropColumn('narration_finalized_at');
        });
    }
};
