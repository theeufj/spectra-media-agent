<?php

namespace App\Services\GoogleAds;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V26\Resources\ConversionAction;
use Google\Ads\GoogleAds\V26\Resources\ConversionAction\ValueSettings;
use Google\Ads\GoogleAds\V26\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V26\Services\MutateConversionActionsRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Operator-owned provisioning; never changes account primaries or campaign goals. */
class ProvisionPaidSetupConversion extends BaseGoogleAdsService
{
    public const NAME = 'Spectra — Paid Setup Purchase';

    public const RESOURCE_SETTING = 'conversion_resource_name.paid_setup';

    public const JOURNAL_SETTING = 'conversion_provisioning.paid_setup';

    /** New payments switch destinations only after readback and import validation succeed. */
    public function provision(DataManagerService $dataManager): array
    {
        if (! $this->customer) {
            throw new \LogicException('The own-site customer is required to provision a Purchase action.');
        }
        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! ctype_digit($customerId) || $customerId !== (string) config('conversions.google_ads_customer_id')
            || (auth()->check() && ! auth()->user()->hasRole('admin'))) {
            throw new \LogicException('Only the own-site account operator can provision its setup Purchase action.');
        }
        $lock = Cache::lock('conversion-provisioning:paid-setup:'.$customerId, 120);
        if (! $lock->get()) {
            throw new \RuntimeException('Paid setup conversion provisioning is already in progress.');
        }
        try {
            $configured = Setting::get(self::RESOURCE_SETTING);
            if ($configured !== null && (! is_string($configured) || ! $this->validResource($customerId, $configured))) {
                throw new \RuntimeException('The configured paid setup action belongs to an invalid account or resource.');
            }
            $actions = $this->readActions($customerId, $configured);
            if (count($actions) > 1) {
                throw new \RuntimeException('Multiple paid setup actions match; an operator must select the intended action.');
            }
            $action = $actions[0] ?? null;
            $journal = Setting::get(self::JOURNAL_SETTING, []);
            if (! is_array($journal)) {
                throw new \RuntimeException('The paid setup provisioning journal is invalid; review it before creating an action.');
            }
            if (isset($journal['customer_id']) && $journal['customer_id'] !== $customerId) {
                throw new \RuntimeException('The paid setup provisioning journal belongs to a different Google account.');
            }
            if (! $action) {
                if ($configured !== null || ! empty($journal['mutation_started_at'])) {
                    throw new \RuntimeException('The paid setup action creation is unverified; repeat readback before any further creation.');
                }
                $this->createAction($customerId, true);
                if ($this->dryRun) {
                    return ['status' => 'create_validated', 'validate_only' => true, 'resource' => null,
                        'delivery_validated' => false, 'live_mapping_changed' => false];
                }
                $journal = ['customer_id' => $customerId, 'name' => self::NAME, 'status' => 'creating',
                    'mutation_started_at' => now()->toIso8601String(), 'automatic_replay_allowed' => false];
                $this->saveJournal($journal); // Durable before the only non-idempotent remote operation.
                $resource = $this->createAction($customerId, false);
                if (! $this->validResource($customerId, $resource)) {
                    throw new \RuntimeException('Google did not return a valid paid setup action; its creation must be read back.');
                }
                $journal = [...$journal, 'resource' => $resource, 'status' => 'created'];
                $this->saveJournal($journal);
                $created = $this->readActions($customerId, $resource);
                if (count($created) !== 1) {
                    throw new \RuntimeException('Google has not confirmed the created paid setup action. Retry readback.');
                }
                $action = $created[0];
            }
            $this->verifyAction($customerId, $action);
            $resource = $action['resourceName'];
            if ($configured !== null && $configured !== $resource) {
                throw new \RuntimeException('The configured paid setup destination changed during provisioning.');
            }
            $validation = $dataManager->ingestConversion(
                operatingAccountId: $customerId, conversionActionId: basename($resource),
                adIdentifiers: ['gclid' => 'DIAGNOSTIC_FAKE_GCLID_0000'], value: 1, currency: 'USD',
                occurredAt: now(), validateOnly: true, transactionId: 'validate-paid-setup-goal',
            );
            if ($validation['success'] !== true) {
                $pendingReason = $configured === null ? $this->pendingDeliveryReason($customerId, $resource, $journal, $validation['error'] ?? null) : null;
                if ($pendingReason !== null) {
                    if (! $this->dryRun) {
                        $this->saveJournal([...$journal, 'status' => 'awaiting_delivery_validation',
                            'delivery_validation' => ['success' => false, 'validate_only' => true,
                                'pending_reason' => $pendingReason, 'checked_at' => now()->toIso8601String()]]);
                    }

                    return ['status' => 'awaiting_delivery_validation', 'validate_only' => $this->dryRun,
                        'resource' => $resource, 'delivery_validated' => false, 'retry_after_seconds' => 1800,
                        'live_mapping_changed' => false, 'reported_conversions_verified' => false];
                }
                throw new \RuntimeException('The paid setup action cannot accept import validation yet; its routing has not been changed.');
            }
            if (! $this->dryRun) {
                // No account primary is promoted: a campaign must explicitly
                // select this secondary action in its reviewed custom goal.
                DB::transaction(function () use ($resource, $journal, $customerId): void {
                    $mapping = Setting::firstOrCreate(['key' => self::RESOURCE_SETTING], ['value' => $resource, 'type' => 'string']);
                    if ($mapping->value !== $resource || $mapping->type !== 'string') {
                        throw new \RuntimeException('The paid setup mapping changed during validation; its new value has been preserved.');
                    }
                    Cache::forget('setting_'.self::RESOURCE_SETTING);
                    $this->saveJournal([...$journal, 'customer_id' => $customerId, 'name' => self::NAME,
                        'resource' => $resource, 'status' => 'ready', 'verified_at' => now()->toIso8601String(),
                        'delivery_validation' => ['success' => true, 'validate_only' => true],
                        'automatic_replay_allowed' => false]);
                });
            }

            return ['status' => 'ready', 'validate_only' => $this->dryRun, 'resource' => $resource,
                'delivery_validated' => true, 'live_mapping_changed' => ! $this->dryRun && $configured !== $resource,
                'reported_conversions_verified' => false];
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function readActions(string $customerId, ?string $resource): array
    {
        $this->ensureClient();
        $condition = $resource ? "conversion_action.resource_name = '{$resource}'"
            : "conversion_action.name = '".self::NAME."'";
        $query = 'SELECT conversion_action.resource_name, conversion_action.name, conversion_action.status, '
            .'conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal, '
            .'conversion_action.counting_type, conversion_action.value_settings.default_value, '
            .'conversion_action.value_settings.default_currency_code, conversion_action.value_settings.always_use_default_value '
            .'FROM conversion_action WHERE '.$condition;
        $actions = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $actions[] = json_decode($row->getConversionAction()->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $actions;
    }

    protected function createAction(string $customerId, bool $validateOnly): ?string
    {
        $this->ensureClient();
        $action = new ConversionAction(['name' => self::NAME, 'type' => ConversionActionType::UPLOAD_CLICKS,
            'category' => ConversionActionCategory::PURCHASE, 'status' => ConversionActionStatus::ENABLED,
            // Count purchases by unique Stripe transaction, including braid-based imports.
            'primary_for_goal' => false, 'counting_type' => ConversionActionCountingType::MANY_PER_CLICK,
            'view_through_lookback_window_days' => 1, 'click_through_lookback_window_days' => 30,
            'value_settings' => new ValueSettings(['default_value' => 0, 'default_currency_code' => 'USD', 'always_use_default_value' => false])]);
        $previous = $this->dryRun;
        $this->dryRun = $previous || $validateOnly;
        try {
            $response = $this->client->getConversionActionServiceClient()->mutateConversionActions(new MutateConversionActionsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [new ConversionActionOperation(['create' => $action])],
            ]));
            $results = $response->getResults();

            return $this->dryRun || count($results) !== 1 ? null : $results[0]->getResourceName();
        } finally {
            $this->dryRun = $previous;
        }
    }

    private function validResource(string $customerId, mixed $resource): bool
    {
        return is_string($resource)
            && preg_match('#^customers/'.preg_quote($customerId, '#').'/conversionActions/\d+$#D', $resource) === 1;
    }

    private function verifyAction(string $customerId, array $action): void
    {
        if (! $this->validResource($customerId, $action['resourceName'] ?? null)
            || $action['resourceName'] === Setting::get('conversion_resource_name.paid_subscription')
            || ($action['name'] ?? null) !== self::NAME || ($action['status'] ?? null) !== 'ENABLED'
            || ($action['type'] ?? null) !== 'UPLOAD_CLICKS' || ($action['category'] ?? null) !== 'PURCHASE'
            || ($action['origin'] ?? null) !== 'WEBSITE' || ($action['primaryForGoal'] ?? false) !== false
            || ($action['countingType'] ?? null) !== 'MANY_PER_CLICK'
            || ($action['valueSettings']['defaultCurrencyCode'] ?? null) !== 'USD'
            || ($action['valueSettings']['defaultValue'] ?? 0) != 0
            || ($action['valueSettings']['alwaysUseDefaultValue'] ?? false) !== false) {
            throw new \RuntimeException('The paid setup action does not match its reviewed secondary Purchase configuration.');
        }
    }

    private function saveJournal(array $journal): void
    {
        Setting::set(self::JOURNAL_SETTING, json_encode($journal, JSON_THROW_ON_ERROR), 'json');
    }

    /** Propagation is a bounded, specifically identified state, never a generic upload-error retry. */
    private function pendingDeliveryReason(string $customerId, string $resource, array $journal, mixed $error): ?string
    {
        if (($journal['customer_id'] ?? null) !== $customerId || ($journal['name'] ?? null) !== self::NAME
            || ($journal['resource'] ?? null) !== $resource
            || ! in_array($journal['status'] ?? null, ['created', 'awaiting_delivery_validation'], true)
            || ! is_string($journal['mutation_started_at'] ?? null) || ! is_string($error)
            || ! preg_match('/\AHTTP 400:\s*(\{.*\})\z/sD', $error, $matches)) {
            return null;
        }
        try {
            $started = CarbonImmutable::parse($journal['mutation_started_at']);
            $now = CarbonImmutable::instance(now());
            if ($started->greaterThan($now) || $started->lessThanOrEqualTo($now->subDay())) {
                return null;
            }
            $body = json_decode($matches[1], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $failure = $body['error'] ?? null;
        if (! is_array($failure) || ($failure['code'] ?? null) !== 400
            || ! in_array($failure['status'] ?? null, ['INVALID_ARGUMENT', 'FAILED_PRECONDITION'], true)
            || ! is_array($failure['details'] ?? null) || $failure['details'] === []) {
            return null;
        }
        $reasons = [];
        foreach ($failure['details'] as $detail) {
            if (! is_array($detail)) {
                return null;
            }
            if (isset($detail['fieldViolations']) && is_array($detail['fieldViolations']) && $detail['fieldViolations'] !== []) {
                foreach ($detail['fieldViolations'] as $violation) {
                    if (! is_array($violation)) {
                        return null;
                    }
                    $reason = $violation['reason'] ?? null;
                    if ($reason === 'CONVERSION_ACTION_TOO_RECENTLY_CREATED') {
                        $reasons[] = $reason;
                    } elseif ($reason === 'NOT_FOUND' && in_array($violation['description'] ?? null, ['ResourceNotFound', 'Resource not found.'], true)
                        && ($violation['field'] ?? null) === 'events.events[0].destination_references') {
                        $reasons[] = 'NOT_FOUND';
                    } else {
                        return null; // Mixed invalid fields are not action propagation.
                    }
                }
            } elseif (($detail['reason'] ?? null) === 'CONVERSION_ACTION_TOO_RECENTLY_CREATED') {
                $reasons[] = $detail['reason'];
            } elseif (($detail['@type'] ?? null) === 'type.googleapis.com/google.rpc.ErrorInfo'
                && ($detail['reason'] ?? null) === 'INVALID_ARGUMENT'
                && ($detail['domain'] ?? null) === 'datamanager.googleapis.com') {
                continue; // The transport envelope adds no field failure beyond BadRequest below.
            } elseif (in_array($detail['@type'] ?? null, ['type.googleapis.com/google.rpc.LocalizedMessage', 'type.googleapis.com/google.rpc.Help'], true)) {
                continue; // Non-error explanatory context cannot establish propagation alone.
            } else {
                return null;
            }
        }

        return $reasons === [] ? null : (in_array('CONVERSION_ACTION_TOO_RECENTLY_CREATED', $reasons, true)
            ? 'CONVERSION_ACTION_TOO_RECENTLY_CREATED' : 'NOT_FOUND');
    }
}
