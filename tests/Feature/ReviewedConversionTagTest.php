<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewedConversionTagTest extends TestCase
{
    use DatabaseTransactions;

    private const STRATEGY = '01a16781-64fe-7215-bf44-7024efab6990';

    private array $triggers = [];

    private array $tags = [];

    private array $variables = [];

    private bool $tamperLiveVariable = false;

    private array $writes = [];

    private bool $createCompilerError = false;

    private bool $publishCompilerError = false;

    private bool $tamperLiveTag = false;

    private bool $reorderLiveFields = false;

    private ?array $liveTagsOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.gtm.platform_refresh_token' => 'test-refresh',
            'services.gtm.platform_account_id' => '111',
        ]);
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();
            if ($url === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'test-token']);
            }
            if ($method !== 'GET') {
                $this->writes[] = ['method' => $method, 'url' => $url, 'data' => $request->data()];
            }
            if (str_ends_with($url, '/triggers')) {
                if ($method === 'GET') {
                    return Http::response(['trigger' => array_values($this->triggers)]);
                }
                $id = (string) (101 + count($this->triggers));
                $this->triggers[$id] = $request->data() + ['triggerId' => $id];

                return Http::response($this->triggers[$id]);
            }
            if (str_ends_with($url, '/variables')) {
                if ($method === 'GET') {
                    return Http::response(['variable' => array_values($this->variables)]);
                }
                $id = (string) (301 + count($this->variables));
                $this->variables[$id] = $request->data() + ['variableId' => $id];

                return Http::response($this->variables[$id]);
            }
            if (preg_match('#/tags(?:/(\d+))?$#', $url, $matches)) {
                if ($method === 'GET') {
                    return Http::response(['tag' => array_values($this->tags)]);
                }
                if ($method === 'DELETE') {
                    unset($this->tags[$matches[1]]);

                    return Http::response([]);
                }
                $id = $matches[1] ?? (string) (201 + count($this->tags));
                $this->tags[$id] = $request->data() + ['tagId' => $id];

                return Http::response($this->tags[$id]);
            }
            if (str_ends_with($url, ':create_version')) {
                return Http::response(['compilerError' => $this->createCompilerError,
                    'containerVersion' => $this->version()]);
            }
            if (str_ends_with($url, ':publish')) {
                return Http::response(['compilerError' => $this->publishCompilerError,
                    'containerVersion' => $this->version()]);
            }
            if ($method === 'GET' && (str_ends_with($url, '/versions:live') || str_ends_with($url, '/versions/7'))) {
                $version = $this->version();
                if ($this->liveTagsOverride !== null) {
                    $version['tag'] = $this->liveTagsOverride;
                }
                if ($this->tamperLiveTag) {
                    $version['tag'][0]['firingTriggerId'] = ['2147479553'];
                }
                if ($this->tamperLiveVariable && isset($version['variable'][0])) {
                    $version['variable'][0]['parameter'][0]['value'] = 'unrelated.transaction_id';
                }
                if ($this->reorderLiveFields) {
                    $version['tag'][0]['parameter'] = array_reverse($version['tag'][0]['parameter']);
                    $version['trigger'][0]['filter'] = array_reverse($version['trigger'][0]['filter']);
                    foreach (['filter', 'customEventFilter'] as $field) {
                        foreach ($version['trigger'][0][$field] ?? [] as $index => $condition) {
                            $condition['parameter'] = array_reverse($condition['parameter']);
                            $version['trigger'][0][$field][$index] = array_reverse($condition, true);
                        }
                    }
                }

                return Http::response($version);
            }
            if (str_ends_with($url, '/workspaces')) {
                return Http::response(['workspace' => [['name' => 'Default Workspace', 'workspaceId' => '1']]]);
            }
            if (str_contains($url, '/built_in_variables')) {
                return Http::response(['builtInVariable' => []]);
            }

            throw new \RuntimeException('Unexpected fake GTM request: '.$method.' '.$url);
        });
    }

    private function version(): array
    {
        return ['path' => 'accounts/111/containers/222/versions/7', 'containerVersionId' => '7',
            'tag' => array_values($this->tags), 'trigger' => array_values($this->triggers), 'variable' => array_values($this->variables)];
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes + [
            'website' => 'https://business.example', 'gtm_container_id' => 'GTM-TEST123',
            'gtm_account_id' => '111', 'gtm_workspace_id' => '1', 'gtm_installed' => false,
            'conversion_tracking_verified_at' => null,
            'gtm_config' => ['container_path' => 'accounts/111/containers/222'],
        ]);
    }

    private function configure(Customer $customer, array $goal): array
    {
        return app(GTMContainerService::class)->configureReviewedConversionTag(
            $customer, self::STRATEGY, $goal, 'AW-123456789', 'reviewed-label'
        );
    }

    public function test_named_success_event_is_the_only_trigger_and_configuration_is_not_tracking_proof(): void
    {
        $customer = $this->customer();
        $result = $this->configure($customer, ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->tags);
        $this->assertCount(1, $this->triggers);
        $trigger = array_values($this->triggers)[0];
        $this->assertSame('customEvent', $trigger['type']);
        $this->assertSame('{{_event}}', $trigger['customEventFilter'][0]['parameter'][0]['value']);
        $this->assertSame('lead_completed', $trigger['customEventFilter'][0]['parameter'][1]['value']);
        $this->assertSame('business.example', $trigger['filter'][0]['parameter'][1]['value']);
        $tag = array_values($this->tags)[0];
        $this->assertSame([$trigger['triggerId']], $tag['firingTriggerId']);
        $this->assertNotContains('2147479553', $tag['firingTriggerId']);
        $this->assertSame('123456789', collect($tag['parameter'])->firstWhere('key', 'conversionId')['value']);
        $this->assertSame('reviewed-label', collect($tag['parameter'])->firstWhere('key', 'conversionLabel')['value']);
        $this->assertFalse($customer->fresh()->gtm_installed);
        $this->assertNull($customer->fresh()->conversion_tracking_verified_at);
    }

    public function test_purchase_tag_requires_positive_actual_amount_currency_and_unique_transaction_and_verifies_live_variables(): void
    {
        $customer = $this->customer();
        $goal = ['objective' => 'purchase', 'trigger' => 'event', 'event_name' => 'purchase_completed'];
        $this->assertTrue($this->configure($customer, $goal)['success']);
        $this->assertTrue($this->configure($customer, $goal)['success']);
        $this->assertCount(3, $this->variables);
        $parameters = collect(array_values($this->tags)[0]['parameter'])->pluck('value', 'key')->all();
        $this->assertSame('{{Site to Spend '.self::STRATEGY.' purchase transaction_id}}', $parameters['orderId']);
        $this->assertSame('{{Site to Spend '.self::STRATEGY.' purchase value}}', $parameters['conversionValue']);
        $this->assertSame('{{Site to Spend '.self::STRATEGY.' purchase currency}}', $parameters['currencyCode']);
        $trigger = array_values($this->triggers)[0];
        $this->assertSame('purchase_completed', $trigger['customEventFilter'][0]['parameter'][1]['value']);
        $this->assertCount(5, $trigger['filter']);
        $this->assertSame('greater', $trigger['filter'][4]['type']);
        $this->assertSame('0', $trigger['filter'][4]['parameter'][1]['value']);
        $this->assertSame(['ecommerce.transaction_id', 'ecommerce.value', 'ecommerce.currency'], array_map(fn ($variable) => $variable['parameter'][0]['value'], array_values($this->variables)));
        $this->assertFalse($customer->fresh()->gtm_installed);
        $this->assertNull($customer->fresh()->conversion_tracking_verified_at);
        $this->tamperLiveVariable = true;
        $this->assertFalse($this->configure($customer, $goal)['success']);
    }

    public function test_confirmation_page_is_exact_and_does_not_add_any_form_or_broad_url_trigger(): void
    {
        $result = $this->configure($this->customer(), ['objective' => 'signup', 'trigger' => 'thank_you_url',
            'thank_you_url' => 'https://business.example/registration/complete']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->triggers);
        $trigger = array_values($this->triggers)[0];
        $this->assertSame('pageview', $trigger['type']);
        $this->assertSame('equals', $trigger['filter'][1]['type']);
        $this->assertSame('{{Page URL}}', $trigger['filter'][1]['parameter'][0]['value']);
        $this->assertSame('https://business.example/registration/complete', $trigger['filter'][1]['parameter'][1]['value']);
    }

    public function test_retries_and_edits_keep_one_owned_tag_and_replace_its_trigger_without_touching_other_tags(): void
    {
        $this->tags['999'] = ['tagId' => '999', 'name' => 'Another campaign tag', 'type' => 'html',
            'firingTriggerId' => ['unrelated']];
        $customer = $this->customer();
        $goal = ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed'];

        $this->assertTrue($this->configure($customer, $goal)['success']);
        $firstTag = collect($this->tags)->firstWhere('name', 'Site to Spend '.self::STRATEGY.' conversion');
        $this->assertTrue($this->configure($customer, $goal)['success']);
        $this->assertCount(1, $this->triggers);
        $this->assertCount(2, $this->tags);
        $this->assertTrue($this->configure($customer, ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'qualified_lead'])['success']);

        $updated = collect($this->tags)->firstWhere('name', 'Site to Spend '.self::STRATEGY.' conversion');
        $this->assertCount(2, $this->tags);
        $this->assertSame($firstTag['tagId'], $updated['tagId']);
        $this->assertCount(1, $updated['firingTriggerId']);
        $this->assertNotSame($firstTag['firingTriggerId'], $updated['firingTriggerId']);
        $this->assertSame(['unrelated'], $this->tags['999']['firingTriggerId']);
        $this->assertEmpty(array_filter($this->writes, fn ($write) => str_ends_with($write['url'], '/tags/999')));
    }

    public function test_foreign_platform_container_is_rejected_before_authentication_or_writes(): void
    {
        $result = $this->configure($this->customer(['gtm_account_id' => '333',
            'gtm_config' => ['container_path' => 'accounts/333/containers/222']]),
            ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed']);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_compiler_error_during_version_creation_cannot_report_success(): void
    {
        $this->createCompilerError = true;
        $result = $this->configure($this->customer(), ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed']);

        $this->assertFalse($result['success']);
        $this->assertEmpty(array_filter($this->writes, fn ($write) => str_ends_with($write['url'], ':publish')));
    }

    public function test_compiler_error_during_publication_cannot_report_success(): void
    {
        $this->publishCompilerError = true;
        $result = $this->configure($this->customer(), ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed']);

        $this->assertFalse($result['success']);
    }

    public function test_live_version_with_a_different_trigger_cannot_be_treated_as_published_configuration(): void
    {
        $this->tamperLiveTag = true;
        $result = $this->configure($this->customer(), ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed']);

        $this->assertFalse($result['success']);
    }

    public function test_equivalent_google_readback_is_verified_despite_json_field_and_condition_order(): void
    {
        $this->reorderLiveFields = true;
        $result = $this->configure($this->customer(), ['objective' => 'lead', 'trigger' => 'thank_you_url',
            'thank_you_url' => 'https://business.example/enquiry/complete']);

        $this->assertTrue($result['success']);
    }

    public function test_replacement_by_an_existing_action_retires_only_the_selected_campaign_tag(): void
    {
        $this->tags['999'] = ['tagId' => '999', 'name' => 'Another campaign tag', 'type' => 'html',
            'firingTriggerId' => ['unrelated']];
        $customer = $this->customer();
        $this->assertTrue($this->configure($customer, ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed'])['success']);

        $result = app(GTMContainerService::class)->retireReviewedConversionTag($customer, self::STRATEGY);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->tags);
        $this->assertSame('Another campaign tag', $this->tags['999']['name']);
        $this->assertSame(['unrelated'], $this->tags['999']['firingTriggerId']);
        $this->assertCount(1, array_filter($this->writes, fn ($write) => $write['method'] === 'DELETE'));
    }

    public function test_a_new_hold_after_trigger_creation_prevents_tag_and_version_writes(): void
    {
        try {
            app(GTMContainerService::class)->configureReviewedConversionTag($this->customer(), self::STRATEGY,
                ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'lead_completed'], 'AW-123456789', 'reviewed-label',
                function () {
                    if ($this->triggers !== []) {
                        throw new \RuntimeException('Campaign authorization changed.');
                    }
                });
            $this->fail('A new hold must stop subsequent tracking writes.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Campaign authorization changed.', $e->getMessage());
        }

        $this->assertCount(1, $this->triggers);
        $this->assertSame([], $this->tags);
        $this->assertEmpty(array_filter($this->writes, fn ($write) => str_ends_with($write['url'], ':create_version') || str_ends_with($write['url'], ':publish')));
    }

    public function test_retirement_retry_does_not_confuse_a_deleted_draft_with_a_tag_still_live(): void
    {
        $this->liveTagsOverride = [['tagId' => '201', 'name' => 'Site to Spend '.self::STRATEGY.' conversion',
            'type' => 'awct', 'firingTriggerId' => ['101']]];
        $this->createCompilerError = true;

        $result = app(GTMContainerService::class)->retireReviewedConversionTag($this->customer(), self::STRATEGY);

        $this->assertFalse($result['success'], 'A retry must finish publishing retirement or verify live absence.');
        $this->assertEmpty(array_filter($this->writes, fn ($write) => str_ends_with($write['url'], ':publish')));
    }
}
