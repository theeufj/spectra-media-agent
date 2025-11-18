<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSitemap;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to crawl a customer's sitemap and trigger brand extraction on completion.
 * 
 * This will:
 * 1. Parse the sitemap XML
 * 2. Dispatch CrawlPage jobs in a batch for each URL
 * 3. Automatically trigger ExtractBrandGuidelines when all pages are crawled
 */
class CrawlCustomerSitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitemap:crawl {customer_id} {sitemap_url}
                            {--force : Force re-crawl even if knowledge base exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl a customer\'s sitemap, populate knowledge base, and extract brand guidelines';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $customerId = $this->argument('customer_id');
        $sitemapUrl = $this->argument('sitemap_url');
        
        $customer = Customer::find($customerId);
        
        if (!$customer) {
            $this->error("Customer with ID {$customerId} not found.");
            return 1;
        }
        
        if (!$customer->users()->exists()) {
            $this->error("Customer {$customerId} has no associated users.");
            return 1;
        }
        
        $user = $customer->users()->first();
        
        $this->info("Starting sitemap crawl for customer: {$customer->name}");
        $this->info("Sitemap URL: {$sitemapUrl}");
        $this->newLine();
        
        // Dispatch the CrawlSitemap job
        CrawlSitemap::dispatch($user, $sitemapUrl, $customer->id);
        
        $this->info("✓ CrawlSitemap job dispatched successfully!");
        $this->newLine();
        $this->line("The job will:");
        $this->line("  1. Parse the sitemap XML");
        $this->line("  2. Create a batch of CrawlPage jobs for each URL");
        $this->line("  3. Populate the knowledge base with page content");
        $this->line("  4. Automatically trigger brand extraction when complete");
        $this->newLine();
        $this->comment("Monitor progress with: php artisan queue:work");
        $this->comment("View batches with: php artisan queue:monitor");
        
        Log::info("CrawlCustomerSitemap command executed", [
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'sitemap_url' => $sitemapUrl,
        ]);
        
        return 0;
    }
}
