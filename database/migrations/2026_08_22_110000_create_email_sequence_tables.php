<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up email chains, editable from the admin portal.
 *
 * Two audiences, both of which the platform currently loses entirely:
 * people who typed their website into the landing page and never came back,
 * and people who signed up and never did anything. Today the first group is
 * not even recorded — Api\DemoController emails the team and discards the
 * lead — and 23 of 39 registered users have never created an account.
 *
 * The tables are separate rather than a single JSON blob because each step's
 * timing and copy is edited individually in the admin portal, and because
 * `email_sequence_sends` has to be queryable to guarantee nobody is sent the
 * same step twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // People who asked the landing page to look at their website. They gave
        // an email to get a result, so they are a contact rather than a scrape
        // — but nothing recorded them until now.
        Schema::create('landing_leads', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('first_name')->nullable();
            $table->string('url')->nullable();

            // Set when they later register, so the lead sequence stops and the
            // signed-up sequence can take over instead of both running.
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique('email');
            $table->index(['unsubscribed_at', 'converted_at']);
        });

        Schema::create('email_sequences', function (Blueprint $table) {
            $table->id();
            // Stable identifier the code dispatches against; the label is what
            // the admin portal shows and may be renamed freely.
            $table->string('key')->unique();
            $table->string('label');
            $table->text('description')->nullable();

            // Which population this chain targets. Kept as a string rather than
            // an enum column so a new audience does not need a migration.
            $table->string('audience');

            $table->string('from_email')->default('james@sitetospend.com');
            $table->string('from_name')->default('James');
            $table->string('reply_to')->nullable();
            $table->string('signature')->default("James\nCo-Founder, Sitetospend");

            // Off until somebody has read the copy and turned it on.
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('email_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sequence_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // Hours after the person entered the audience, not after the
            // previous step — so reordering or disabling a step cannot shift
            // everything after it.
            $table->unsignedInteger('delay_hours');

            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['email_sequence_id', 'position']);
        });

        // One row per step per recipient. The unique index is the guarantee
        // that a retry, an overlapping schedule run or a double dispatch
        // cannot email somebody the same thing twice.
        Schema::create('email_sequence_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sequence_step_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_type', 16);   // lead | user
            $table->unsignedBigInteger('recipient_id');
            $table->string('email');
            $table->timestamp('sent_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();

            $table->unique(['email_sequence_step_id', 'recipient_type', 'recipient_id'], 'sequence_send_unique');
            $table->index(['recipient_type', 'recipient_id']);
        });

        // Replies arriving back through Resend's inbound webhook. Stored so a
        // reply is visible in the admin portal next to what was sent, rather
        // than existing only in three inboxes.
        Schema::create('email_sequence_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sequence_send_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_email')->index();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sequence_replies');
        Schema::dropIfExists('email_sequence_sends');
        Schema::dropIfExists('email_sequence_steps');
        Schema::dropIfExists('email_sequences');
        Schema::dropIfExists('landing_leads');
    }
};
