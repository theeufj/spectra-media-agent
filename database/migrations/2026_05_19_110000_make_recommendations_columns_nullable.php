<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('target_entity')->nullable()->change();
            $table->json('parameters')->nullable()->change();
            $table->text('rationale')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('target_entity')->nullable(false)->change();
            $table->json('parameters')->nullable(false)->change();
            $table->text('rationale')->nullable(false)->change();
        });
    }
};
