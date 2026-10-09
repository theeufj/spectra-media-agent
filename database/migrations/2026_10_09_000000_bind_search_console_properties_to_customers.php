<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Google platform property access is not proof that a customer
            // owns a site. Only a verified customer-specific binding grants
            // reports; never infer these from website or the platform sites list.
            $table->string('search_console_property', 500)->nullable();
            $table->string('search_console_verified_host', 255)->nullable();
            $table->timestamp('search_console_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn([
            'search_console_property', 'search_console_verified_host', 'search_console_verified_at',
        ]));
    }
};
