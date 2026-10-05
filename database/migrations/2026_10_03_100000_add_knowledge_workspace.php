<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('website_url');
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->json('candidates')->nullable();
            $table->json('selected_urls')->nullable();
            $table->timestamps();
        });
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('processing_status')->default('queued')->index();
            $table->text('processing_error')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('source_version')->default(1);
        });
        DB::table('knowledge_bases')->where('content', '!=', '')->update(['processing_status' => 'needs_attention']);
        // Older briefs shared the homepage URL. Give them their own source
        // identity so a later website read cannot overwrite human-supplied facts.
        DB::table('knowledge_bases')->whereNotNull('customer_id')->where('source_type', 'text')
            ->where('original_filename', 'onboarding-business-brief.txt')->whereNull('file_path')
            ->orderBy('id')->chunkById(100, function ($briefs) {
                foreach ($briefs as $brief) {
                    DB::table('knowledge_bases')->where('id', $brief->id)->update(['url' => 'note:onboarding-'.$brief->customer_id]);
                }
            });
        Schema::create('knowledge_base_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('position');
            $table->text('content');
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', 3072)->nullable();
            } else {
                $table->json('embedding')->nullable();
            }
            $table->string('embedding_model')->nullable()->index();
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'source_version', 'position'], 'knowledge_chunk_version_position');
            $table->index(['customer_id', 'embedding_model']);
        });
        Schema::create('knowledge_retrievals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('source_version');
            $table->string('purpose');
            $table->text('query');
            $table->json('positions');
            $table->timestamp('retrieved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_imports');
        Schema::dropIfExists('knowledge_retrievals');
        Schema::dropIfExists('knowledge_base_chunks');
        Schema::table('knowledge_bases', fn (Blueprint $table) => $table->dropColumn([
            'title', 'processing_status', 'processing_error', 'excluded_at', 'fetched_at',
            'indexed_at', 'content_hash', 'source_version',
        ]));
    }
};
