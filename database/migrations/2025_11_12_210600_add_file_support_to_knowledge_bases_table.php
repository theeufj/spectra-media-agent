<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            // Add file_path to store the path to uploaded documents
            $table->string('file_path')->nullable()->after('css_content');
            
            // Add file_type to distinguish between 'pdf', 'text', 'url'
            $table->enum('source_type', ['url', 'pdf', 'text'])->default('url')->after('file_path');
            
            // Add original_filename to store the original file name
            $table->string('original_filename')->nullable()->after('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'source_type', 'original_filename']);
        });
    }
};
