<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spectra_conversion_events', function (Blueprint $table) {
            $table->id();
            $table->string('event');                         // signup, pricing_visit, campaign_live, etc.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gclid')->nullable();             // set if user arrived via a Google Ad
            $table->string('mode');                          // client | server
            $table->decimal('value', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('uploaded_to_google')->default(false);
            $table->timestamps();

            $table->index('event');
            $table->index('created_at');
            $table->index('gclid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spectra_conversion_events');
    }
};
