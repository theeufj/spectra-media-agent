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
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('platform'); // e.g., 'google_ads', 'facebook_ads'
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('account_id')->nullable(); // e.g., Google Ads Customer ID
            $table->string('account_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
