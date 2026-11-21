<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->json('creative_revision')->nullable();
            $table->json('creative_publication')->nullable();
        });
        Schema::table('ad_copies', function (Blueprint $table) {
            $table->uuid('creative_revision_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ad_copies', fn (Blueprint $table) => $table->dropColumn('creative_revision_id'));
        Schema::table('strategies', fn (Blueprint $table) => $table->dropColumn(['creative_revision', 'creative_publication']));
    }
};
