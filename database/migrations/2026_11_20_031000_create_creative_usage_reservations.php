<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_usage_id')->constrained()->cascadeOnDelete();
            $table->string('work_key', 64)->unique();
            $table->string('type', 16);
            $table->boolean('completed')->default(false);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_usage_reservations');
    }
};
