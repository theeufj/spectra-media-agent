<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer record of every email the platform sends. Rows are written by
 * the LogSentEmail listener on Laravel's MessageSent event, so a row means
 * the transport accepted the message — not merely that a job intended to
 * send one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: framework emails (password resets, invites) don't
            // carry a customer; the admin profile matches those by address.
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('to_email')->index();
            $table->string('subject')->nullable();
            $table->string('mailable')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
