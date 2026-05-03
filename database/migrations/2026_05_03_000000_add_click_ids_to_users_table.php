<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gclid')->nullable()->after('email');
            $table->string('fbclid')->nullable()->after('gclid');
            $table->string('msclid')->nullable()->after('fbclid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gclid', 'fbclid', 'msclid']);
        });
    }
};
