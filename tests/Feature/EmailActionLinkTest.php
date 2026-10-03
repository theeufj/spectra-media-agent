<?php

namespace Tests\Feature;

use App\Mail\AdSpendCampaignsPaused;
use App\Mail\AdSpendCampaignsResumed;
use App\Mail\AdSpendLowBalance;
use App\Mail\AdSpendPaymentFailed;
use App\Mail\AdSpendPaymentWarning;
use App\Mail\FirstCampaignReady;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdSpendReconciliationAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailActionLinkTest extends TestCase
{
    use DatabaseTransactions;

    public function test_billing_and_campaign_emails_link_to_real_get_pages_on_the_customer_domain(): void
    {
        $customer = Customer::factory()->create(['tenant_key' => 'realpropertyads']);
        $credit = AdSpendCredit::factory()->paused()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $mailables = [
            new AdSpendPaymentFailed($customer, $credit, 'Declined'),
            new AdSpendPaymentWarning($customer, $credit, 'Declined'),
            new AdSpendLowBalance($customer, $credit, 2.0),
            new AdSpendCampaignsPaused($customer, $credit),
            new AdSpendCampaignsResumed($customer, $credit),
            new FirstCampaignReady($campaign->fresh('customer'), 'Josh'),
        ];

        foreach ($mailables as $mail) {
            preg_match_all('/href="([^"]+)"/', $mail->render(), $matches);
            $this->assertNotEmpty($matches[1], $mail::class.' has no action link');
            foreach ($matches[1] as $escapedUrl) {
                $url = html_entity_decode($escapedUrl, ENT_QUOTES);
                $this->assertSame('realpropertyads.com', parse_url($url, PHP_URL_HOST), $mail::class);
                $this->assertGetRoute($url);
            }
        }
    }

    public function test_admin_billing_alert_links_to_an_existing_get_page(): void
    {
        $alert = new AdSpendReconciliationAlert([], 'the last seven days');
        $url = $alert->toMail(User::factory()->make())->actionUrl;

        $this->assertSame('/admin/revenue', parse_url($url, PHP_URL_PATH));
        $this->assertGetRoute($url);
    }

    public function test_old_email_links_redirect_to_the_replacement_pages(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.revenue.index'));

        $legacyBilling = Route::getRoutes()->match(Request::create('/billing', 'GET'));
        $this->assertSame(route('billing.ad-spend'), $legacyBilling->run()->getTargetUrl());
    }

    public function test_first_party_email_actions_use_named_get_routes(): void
    {
        $files = array_merge(
            glob(resource_path('views/emails/*.blade.php')),
            glob(app_path('Notifications/*.php')),
        );

        foreach ($files as $file) {
            $source = file_get_contents($file);
            preg_match_all('/(?<![A-Za-z])route\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
            foreach ($matches[1] as $name) {
                $route = Route::getRoutes()->getByName($name);
                $this->assertNotNull($route, basename($file)." uses missing route {$name}");
                $this->assertContains('GET', $route->methods(), basename($file)." links to non-GET route {$name}");
            }

            $this->assertDoesNotMatchRegularExpression('/config\([\'\"]app\.url[\'\"]\)\s*\.\s*[\'\"]\//', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/\$this->tenantUrl\(\s*[\'\"]\//', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/href="\{\{\s*url\(\s*[\'\"]\//', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/href="\{\{\s*\(\$tenantBaseUrl[^\n]*\}\}\//', $source, basename($file));
        }
    }

    private function assertGetRoute(string $url): void
    {
        $route = Route::getRoutes()->match(Request::create($url, 'GET'));
        $this->assertContains('GET', $route->methods(), $url);
    }
}
