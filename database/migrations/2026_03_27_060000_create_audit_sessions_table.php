<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('email')->nullable();
            $table->string('platform'); // google, facebook
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->string('google_ads_customer_id')->nullable();
            $table->string('facebook_ad_account_id')->nullable();
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->json('audit_results')->nullable();
            $table->integer('score')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_sessions');
    }
};
