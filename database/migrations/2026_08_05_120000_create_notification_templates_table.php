<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();

            // Stable identifier the code resolves a template by, e.g.
            // 'critical_agent_alert.spend_anomaly' or 'mcc_account_creation_eligible'.
            $table->string('key')->unique();

            // Grouping + human labels for the admin UI.
            $table->string('category')->default('general')->index();
            $table->string('label');
            $table->text('description')->nullable();

            $table->string('channel')->default('mail');

            // Copy templates with {{variable}} placeholders. NULL means "use the code
            // default" — the row can override subject/body independently.
            $table->text('subject')->nullable();
            $table->text('body')->nullable();

            // Who receives it: 'admins' | 'customers' | 'both'. NULL = use code default.
            $table->string('recipients')->nullable();

            // Master on/off switch. When false the email is suppressed entirely.
            $table->boolean('enabled')->default(true);

            // Allowed placeholder variables + sample values for the preview, e.g.
            // {"campaign_name": "Summer Sale", "spend": "63.79"}. Read-only in the UI.
            $table->json('variables')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
