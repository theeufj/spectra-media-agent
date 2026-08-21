<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a support ticket hold a chat conversation.
 *
 * `transcript` is the full exchange — every customer message and every
 * assistant reply, in order. Two reasons it is the whole log rather than just
 * the last answer:
 *
 *  - The assistant replies to paying customers with no human in the loop. The
 *    only way to know whether it is saying sensible things is to be able to
 *    read what it actually said, so every reply is retained for review.
 *  - An admin picking up the ticket needs to see what the customer was already
 *    told. A human reply that contradicts the bot is worse than the bot having
 *    said nothing.
 *
 * One ticket per conversation, not per message. Per-message would fan out an
 * email to every admin on every line typed, which is both unusable and an abuse
 * vector in itself.
 *
 * `source` distinguishes these from tickets raised through the /support-tickets
 * form; they arrive with a different shape and are worth filtering separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('source', 16)->default('form')->after('category');

            // jsonb rather than json: Postgres can index and query into it, and
            // this is the column support will want to search when reviewing
            // what the assistant has been telling people.
            $table->jsonb('transcript')->nullable()->after('description');

            // The admin queue's likely filter: "show me open chat tickets".
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropColumn(['source', 'transcript']);
        });
    }
};
