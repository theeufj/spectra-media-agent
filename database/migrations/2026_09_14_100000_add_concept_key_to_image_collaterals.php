<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rows are the same picture.
 *
 * One generated scene is stored three times — square for feeds, 1200x628 for
 * landscape placements, 300x250 for display — and nothing recorded that they
 * belong together. The filename cannot stand in for it: uniqid() is called
 * inside the format loop, so the three files of one picture share no prefix.
 *
 * The customer pays for that. Campaign 40 generated 8 scenes and the collateral
 * page rendered 24 cards, so every creative appeared three times, in three
 * aspect ratios, with no indication they were the same photograph — which is a
 * large part of why a set that was already too similar looked worse than it
 * was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->string('concept_key', 40)->nullable()->index()->after('format');
        });

        /*
         * Existing rows are left null and each stands alone.
         *
         * There is no honest way to reconstruct the grouping: the filenames do
         * not share a prefix and created_at differs by the seconds each format
         * took to generate. Guessing would merge unrelated pictures under one
         * card, which is worse than showing them separately. Every row written
         * from here on carries a key.
         */
    }

    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->dropColumn('concept_key');
        });
    }
};
