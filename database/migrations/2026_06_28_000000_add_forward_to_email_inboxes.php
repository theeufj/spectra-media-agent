<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_inboxes', function (Blueprint $table) {
            $table->string('forward_to')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('email_inboxes', function (Blueprint $table) {
            $table->dropColumn('forward_to');
        });
    }
};
