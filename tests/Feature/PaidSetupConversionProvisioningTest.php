<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\ProvisionPaidSetupConversion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PaidSetupConversionProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '1234567890';

    private const ACTION = 'customers/1234567890/conversionActions/909';

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['conversions.google_ads_customer_id' => self::ACCOUNT]);
        $this->customer = Customer::factory()->create(['google_ads_customer_id' => self::ACCOUNT]);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/1234567890/conversionActions/808');
    }

    private function action(array $changes = []): array
    {
        return array_replace(['resourceName' => self::ACTION, 'name' => ProvisionPaidSetupConversion::NAME,
            'status' => 'ENABLED', 'type' => 'UPLOAD_CLICKS', 'category' => 'PURCHASE', 'origin' => 'WEBSITE',
            'primaryForGoal' => false, 'countingType' => 'MANY_PER_CLICK',
            'valueSettings' => ['defaultCurrencyCode' => 'USD', 'defaultValue' => 0, 'alwaysUseDefaultValue' => false]], $changes);
    }

    private function service(array $rows = []): PaidSetupProvisionFixture
    {
        return new PaidSetupProvisionFixture($this->customer, $rows, $this->action());
    }

    private function delivery(int $calls = 1, bool $success = true): DataManagerService
    {
        $service = $this->createMock(DataManagerService::class);
        $service->expects($this->exactly($calls))->method('ingestConversion')->willReturnCallback(function (...$arguments) use ($success): array {
            $this->assertSame(self::ACCOUNT, $arguments[0]);
            $this->assertSame('909', $arguments[1]);
            $this->assertTrue($arguments[7], 'Provisioning may validate transport, never upload an invented purchase.');
            $this->assertSame('validate-paid-setup-goal', $arguments[9]);

            return ['success' => $success];
        });

        return $service;
    }

    private function propagationError(string $reason = 'NOT_FOUND', array $extraViolations = []): string
    {
        return 'HTTP 400: '.json_encode(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'INVALID_ARGUMENT', 'domain' => 'datamanager.googleapis.com'],
                ['@type' => 'type.googleapis.com/google.rpc.LocalizedMessage', 'locale' => 'en-US', 'message' => 'There was a problem with the request.'],
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [
                    ['field' => 'events.events[0].destination_references', 'description' => 'Resource not found.', 'reason' => $reason],
                    ...$extraViolations,
                ]]]]], JSON_THROW_ON_ERROR);
    }

    private function rejectedDelivery(string $error): DataManagerService
    {
        $delivery = $this->createMock(DataManagerService::class);
        $delivery->expects($this->once())->method('ingestConversion')->willReturnCallback(function (...$arguments) use ($error): array {
            $this->assertTrue($arguments[7]);

            return ['success' => false, 'error' => $error];
        });

        return $delivery;
    }

    public function test_new_action_is_validated_created_once_and_published_only_after_delivery_readiness(): void
    {
        $service = $this->service();
        $delivery = $this->delivery(2);
        $result = $service->provision($delivery);
        $service->provision($delivery);

        $this->assertSame('ready', $result['status']);
        $this->assertSame(self::ACTION, Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
        $this->assertSame('ready', Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING)['status']);
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertFalse($result['reported_conversions_verified']);
        $this->assertSame('customers/1234567890/conversionActions/808', Setting::get('conversion_resource_name.paid_subscription'));
        $this->assertSame(0, SpectraConversionEvent::count());
    }

    public function test_dry_run_validates_creation_without_publishing_settings_or_uploading_events(): void
    {
        $service = $this->service()->dryRun();
        $result = $service->provision($this->delivery(0));

        $this->assertSame('create_validated', $result['status']);
        $this->assertSame([true], $service->creationCalls);
        $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
        $this->assertDatabaseMissing('settings', ['key' => ProvisionPaidSetupConversion::JOURNAL_SETTING]);
        $this->assertSame(0, SpectraConversionEvent::count());
    }

    public function test_delivery_propagation_failure_preserves_created_action_for_a_read_only_retry(): void
    {
        $service = $this->service();
        try {
            $service->provision($this->delivery(success: false));
            $this->fail('An action that cannot validate imports must not receive new checkout receipts.');
        } catch (\RuntimeException) {
            $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            $this->assertSame('created', Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING)['status']);
        }
        $service->provision($this->delivery());
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertSame(self::ACTION, Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
    }

    public function test_recent_action_propagation_is_pending_without_routing_or_recreating_or_faking_an_event(): void
    {
        $service = $this->service();
        foreach (['NOT_FOUND', 'CONVERSION_ACTION_TOO_RECENTLY_CREATED'] as $reason) {
            $result = $service->provision($this->rejectedDelivery($this->propagationError($reason)));
            $this->assertSame('awaiting_delivery_validation', $result['status']);
            $this->assertSame(1800, $result['retry_after_seconds']);
            $this->assertFalse($result['delivery_validated']);
            $this->assertFalse($result['live_mapping_changed']);
            $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            $this->assertSame($reason, Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING)['delivery_validation']['pending_reason']);
        }
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertSame(0, SpectraConversionEvent::count());
        $this->assertSame('ready', $service->provision($this->delivery())['status']);
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertSame(self::ACTION, Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
    }

    public function test_permission_and_other_validation_errors_are_not_misclassified_as_propagation(): void
    {
        $service = $this->service();
        $errors = ['HTTP 403: {"error":{"code":403,"status":"PERMISSION_DENIED"}}', 'Could not obtain Data Manager access token',
            $this->propagationError(extraViolations: [['field' => 'events.events[0].currency', 'description' => 'Invalid currency', 'reason' => 'INVALID_ARGUMENT']]),
            str_replace('events.events[0].destination_references', 'events.events[0].event_timestamp', $this->propagationError()),
        ];
        foreach ($errors as $error) {
            try {
                $service->provision($this->rejectedDelivery($error));
                $this->fail('An unrelated validation or credentials failure must remain an actionable error.');
            } catch (\RuntimeException) {
                $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            }
        }
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertSame('created', Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING)['status']);
    }

    public function test_propagation_does_not_mask_old_unproven_or_already_live_action_failures(): void
    {
        $journal = ['customer_id' => self::ACCOUNT, 'name' => ProvisionPaidSetupConversion::NAME,
            'resource' => self::ACTION, 'status' => 'created', 'mutation_started_at' => now()->subDays(2)->toIso8601String()];
        $service = $this->service([$this->action()]);
        foreach (['old', 'unproven', 'already_live'] as $case) {
            if ($case === 'unproven') {
                $journal['mutation_started_at'] = now()->toIso8601String();
                $journal['status'] = 'creating';
            } elseif ($case === 'already_live') {
                $journal['status'] = 'created';
                Setting::set(ProvisionPaidSetupConversion::RESOURCE_SETTING, self::ACTION);
            }
            Setting::set(ProvisionPaidSetupConversion::JOURNAL_SETTING, json_encode($journal, JSON_THROW_ON_ERROR), 'json');
            try {
                $service->provision($this->rejectedDelivery($this->propagationError()));
                $this->fail('The pending classification is reserved for a recently created unpublished destination.');
            } catch (\RuntimeException) {
                $this->assertSame([], $service->creationCalls);
                $this->assertSame($case === 'already_live' ? self::ACTION : null, Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            }
        }
    }

    public function test_a_lost_creation_response_is_recovered_by_readback_without_creating_another_action(): void
    {
        $service = $this->service();
        $service->throwAfterCreate = true;
        try {
            $service->provision($this->delivery(0));
            $this->fail('A lost provider response is unverified.');
        } catch (\RuntimeException) {
            $this->assertNotEmpty(Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING)['mutation_started_at']);
        }
        $service->provision($this->delivery());
        $this->assertSame([true, false], $service->creationCalls);
        $this->assertSame(self::ACTION, Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
    }

    public function test_unknown_creation_with_no_readback_is_not_replayed(): void
    {
        $service = $this->service();
        $service->throwBeforeCreate = true;
        foreach (range(1, 2) as $attempt) {
            try {
                $service->provision($this->delivery(0));
                $this->fail('An ambiguous action creation cannot be repeated automatically.');
            } catch (\RuntimeException) {
                $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            }
        }
        $this->assertSame([true, false], $service->creationCalls);
    }

    public function test_mismatched_existing_actions_are_not_changed_or_adopted(): void
    {
        $variants = [['primaryForGoal' => true], ['type' => 'WEBPAGE'], ['status' => 'REMOVED'],
            ['category' => 'SIGNUP'], ['countingType' => 'ONE_PER_CLICK'],
            ['valueSettings' => ['defaultCurrencyCode' => 'USD', 'defaultValue' => 999, 'alwaysUseDefaultValue' => true]]];
        foreach ($variants as $changes) {
            $service = $this->service([$this->action($changes)]);
            try {
                $service->provision($this->delivery(0));
                $this->fail('A differently configured action needs explicit review.');
            } catch (\RuntimeException) {
                $this->assertSame([], $service->creationCalls);
                $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
            }
        }
    }

    public function test_foreign_or_duplicate_existing_destinations_are_rejected_without_writes(): void
    {
        $service = $this->service([$this->action(), $this->action()]);
        try {
            $service->provision($this->delivery(0));
            $this->fail('Duplicate matching names require review.');
        } catch (\RuntimeException) {
            $this->assertSame([], $service->creationCalls);
        }
        Setting::set(ProvisionPaidSetupConversion::RESOURCE_SETTING, 'customers/9876543210/conversionActions/909');
        $service = $this->service();
        $this->expectException(\RuntimeException::class);
        $service->provision($this->delivery(0));
    }

    public function test_unavailable_inventory_does_not_fall_through_to_creation(): void
    {
        $service = $this->service();
        $service->readUnavailable = true;
        try {
            $service->provision($this->delivery(0));
            $this->fail('Missing inventory evidence cannot establish that an action does not exist.');
        } catch (\RuntimeException) {
            $this->assertSame([], $service->creationCalls);
            $this->assertNull(Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING));
        }
    }

    public function test_concurrent_mapping_change_is_preserved_after_transport_validation(): void
    {
        $service = $this->service();
        $delivery = $this->createMock(DataManagerService::class);
        $delivery->method('ingestConversion')->willReturnCallback(function (): array {
            Setting::set(ProvisionPaidSetupConversion::RESOURCE_SETTING, 'customers/1234567890/conversionActions/707');

            return ['success' => true];
        });
        try {
            $service->provision($delivery);
            $this->fail('A changed destination must not be overwritten after a slow provider call.');
        } catch (\RuntimeException) {
            $this->assertSame('customers/1234567890/conversionActions/707', Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
        }
    }

    public function test_existing_secondary_action_can_be_validated_without_publishing_a_dry_run(): void
    {
        $service = $this->service([$this->action()])->dryRun();
        $result = $service->provision($this->delivery());

        $this->assertSame('ready', $result['status']);
        $this->assertSame([], $service->creationCalls);
        $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
        $this->assertDatabaseMissing('settings', ['key' => ProvisionPaidSetupConversion::JOURNAL_SETTING]);
    }

    public function test_another_customer_cannot_provision_the_own_site_action(): void
    {
        $other = Customer::factory()->create(['google_ads_customer_id' => '9876543210']);
        $service = new PaidSetupProvisionFixture($other, [], $this->action());
        try {
            $service->provision($this->delivery(0));
            $this->fail('The own-site tracking destination cannot be provisioned for another account.');
        } catch (\LogicException) {
            $this->assertSame([], $service->creationCalls);
            $this->assertDatabaseMissing('settings', ['key' => ProvisionPaidSetupConversion::JOURNAL_SETTING]);
        }
    }

    public function test_a_different_account_journal_cannot_be_replaced_by_adopting_an_existing_action(): void
    {
        $journal = ['customer_id' => '9876543210', 'mutation_started_at' => now()->toIso8601String()];
        Setting::set(ProvisionPaidSetupConversion::JOURNAL_SETTING, json_encode($journal, JSON_THROW_ON_ERROR), 'json');
        $service = $this->service([$this->action()]);
        try {
            $service->provision($this->delivery(0));
            $this->fail('A previous account\'s uncertain creation requires review.');
        } catch (\RuntimeException) {
            $this->assertSame([], $service->creationCalls);
            $this->assertSame($journal, Setting::get(ProvisionPaidSetupConversion::JOURNAL_SETTING));
            $this->assertNull(Setting::get(ProvisionPaidSetupConversion::RESOURCE_SETTING));
        }
    }
}

class PaidSetupProvisionFixture extends ProvisionPaidSetupConversion
{
    public array $creationCalls = [];

    public bool $throwAfterCreate = false;

    public bool $throwBeforeCreate = false;

    public bool $readUnavailable = false;

    public function __construct(Customer $customer, private array $rows, private array $created)
    {
        $this->customer = $customer;
    }

    protected function readActions(string $customerId, ?string $resource): array
    {
        if ($this->readUnavailable) {
            throw new \RuntimeException('Inventory temporarily unavailable.');
        }

        return $resource ? array_values(array_filter($this->rows, fn ($row) => $row['resourceName'] === $resource)) : $this->rows;
    }

    protected function createAction(string $customerId, bool $validateOnly): ?string
    {
        $this->creationCalls[] = $validateOnly;
        if ($validateOnly) {
            return null;
        }
        if ($this->throwBeforeCreate) {
            throw new \RuntimeException('Provider outcome unavailable.');
        }
        $this->rows[] = $this->created;
        if ($this->throwAfterCreate) {
            throw new \RuntimeException('Provider response lost.');
        }

        return $this->created['resourceName'];
    }
}
