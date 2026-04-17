<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('source')->default('http')->index(); // http, queue, console
            $table->string('file')->nullable();
            $table->integer('line')->nullable();
            $table->text('message');
            $table->longText('trace')->nullable();
            $table->string('url')->nullable();
            $table->string('method')->nullable();
            $table->string('job_class')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_exceptions');
    }
};
