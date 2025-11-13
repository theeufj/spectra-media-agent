<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB; // Import the DB facade to run raw SQL statements.
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * This 'up' method is executed when we run `php artisan migrate`.
     * It's the equivalent of the `Up` function in a Go migration script.
     */
    public function up(): void
    {
        /*
         * In Go, you might use a specific driver feature or execute a raw query
         * to enable a database extension. Here, we use the DB facade for that.
         * This ensures the 'vector' type is available before we create the table.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        // Schema::create is a static method that initiates the creation of a new table.
        Schema::create('knowledge_bases', function (Blueprint $table) {
            // $table->id() creates an auto-incrementing, unsigned BIGINT primary key column named 'id'.
            $table->id();

            // $table->foreignId('user_id') creates a column to hold the foreign key.
            // ->constrained() adds the foreign key constraint, linking it to the 'id' column on the 'users' table.
            // ->onDelete('cascade') means if a user is deleted, all their knowledge base entries will be deleted too.
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // $table->string('url') creates a VARCHAR column to store the URL of the crawled page.
            $table->string('url')->index();

            // $table->text('content') creates a TEXT column, which can hold a large amount of text.
            $table->text('content');

            /*
             * This adds a 'vector' column named 'embedding'.
             * The number (768) is the dimension of the vector. This must match the
             * output dimension of the embedding model we'll use (e.g., Google's text-embedding-004).
             * We'll make it nullable for now.
             */
            $table->vector('embedding', 768)->nullable();

            // $table->timestamps() is a helper that creates two TIMESTAMP columns: `created_at` and `updated_at`.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * This 'down' method is executed when we run `php artisan migrate:rollback`.
     * It should contain the logic to undo whatever the 'up' method did.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
