<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->unsignedInteger('profile_version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->json('manual_overrides')->nullable();
            $table->json('proposed_profile')->nullable();
            $table->json('extraction_changes')->nullable();
            $table->json('source_suggestions')->nullable();
            $table->json('source_snapshot')->nullable();
        });
        DB::table('brand_guidelines')->where('user_verified', true)->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $overrides = [];
                foreach (\App\Services\Brands\BrandProfileReview::FIELDS as $field) {
                    $overrides[$field] = $row->{$field} === null ? null : json_decode($row->{$field}, true);
                }
                DB::table('brand_guidelines')->where('id', $row->id)->update(['approved_version' => 1, 'manual_overrides' => json_encode($overrides)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_guidelines', fn (Blueprint $table) => $table->dropColumn(['profile_version', 'approved_version', 'manual_overrides', 'proposed_profile', 'extraction_changes', 'source_suggestions', 'source_snapshot']));
    }
};
