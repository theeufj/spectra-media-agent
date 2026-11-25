<?php

namespace App\Console\Commands;

use App\Support\HelpArticles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateLlmsFull extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-llms-full';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates public/llms-full.txt containing the full context of marketing pages and blog articles for AI crawlers.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating llms-full.txt...');

        // The short index is the public offer. Use the same description in
        // the expanded copy so crawler documents agree on scope and billing.
        $content = File::get(public_path('llms.txt'))."\n\n";

        // Section: Blog Articles
        $content .= "## 2. Expert Guides & Blog Articles\n\n";

        $articles = HelpArticles::all();
        foreach ($articles as $articleData) {
            $slug = $articleData['slug'];
            // Fetch full content via find()
            $article = HelpArticles::find($slug);
            if ($article) {
                // Strip HTML tags for clean text, replace <p>, <h2>, etc. with plain text blocks
                $cleanContent = strip_tags($article['content'], '<h1><h2><h3><h4><h5><h6><p><ul><ol><li><strong><em><blockquote>');

                // Formatter
                $cleanContent = preg_replace('/<h[1-6]>(.*?)<\/h[1-6]>/', "\n### $1\n", $cleanContent);
                $cleanContent = preg_replace('/<p>(.*?)<\/p>/', "$1\n\n", $cleanContent);
                $cleanContent = preg_replace('/<li>(.*?)<\/li>/', "- $1\n", $cleanContent);
                $cleanContent = strip_tags($cleanContent);
                // Decode HTML entities
                $cleanContent = html_entity_decode($cleanContent);

                $content .= "### Article: {$article['title']}\n";
                $content .= "**Category:** {$article['category']} | **Read Time:** {$article['read_time']}\n";
                $content .= "**Description:** {$article['description']}\n\n";
                $content .= "{$cleanContent}\n\n";
                $content .= "---\n\n";
            }
        }

        File::put(public_path('llms-full.txt'), $content);

        $this->info('Successfully generated llms-full.txt!');
    }
}
