<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\HelpArticles;
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

        $content = "# sitetospend.com - Full AI Knowledge Base\n\n";
        $content .= "> This document is a complete text compilation of sitetospend.com's marketing pages, features, and blog articles. It is designed to be easily ingested by AI language models.\n\n";

        // Section: Marketing Copy
        $content .= "## 1. What We Do\n";
        $content .= "sitetospend.com is an AI-powered Google Ads management platform that automates campaign creation, optimisation, and scaling for small businesses and agencies. We remove the complexity of paid advertising by deploying AI agents that continuously monitor, optimise, and improve your campaigns. Instead of spending hours managing bids, keywords, and ad copy, you connect your account and let our system work around the clock.\n\n";
        
        $content .= "### Core Features\n";
        $content .= "- **Automated Campaign Creation**: Build Search, Display, and Performance Max campaigns automatically.\n";
        $content .= "- **Smart Bidding Optimisation**: 24/7 adjustment of bids to hit ROAS and CPA targets.\n";
        $content .= "- **AI Ad Copy Strategy**: Our creative agents continuously test headlines, descriptions, and imagery against each other.\n";
        $content .= "- **Cross-Platform**: Deploy to Google Ads, Meta (Facebook Ads), and Microsoft Ads from one central campaign goal.\n";
        $content .= "- **Self-Healing Agent**: Automatically catches sudden CPC spikes, underperforming ad groups, and broken links, and pauses or fixes them.\n";
        $content .= "- **Competitor Intelligence**: Spies on competitor offers and dynamically adjusts your copy to counteract them.\n\n";

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
