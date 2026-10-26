<?php

namespace App\Services\GoogleAds;

use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use Illuminate\Support\Facades\DB;

/** Observations of the selected goal, without changing Google Ads configuration. */
class CampaignConversionTrackingHealth
{
    public function inspect(Strategy $strategy, ConversionTrackingService $tracking, array $accountActivity): array
    {
        $customer = $strategy->campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $strategy->reusableGoogleCampaignId();
        if (! $resource) {
            throw new \RuntimeException('A deployed Google campaign is required to inspect conversion tracking.');
        }
        if (ctype_digit($resource)) {
            $resource = "customers/{$customerId}/campaigns/{$resource}";
        }
        $state = ['checked_at' => now()->toIso8601String(), 'campaign_resource' => $resource,
            'configuration_status' => 'not_checked', 'account_activity' => $accountActivity,
            'selected_action_activity' => null, 'delivery' => null, 'issues' => []];
        $remoteStatus = $tracking->getCampaignStatus($customerId, $resource);
        if ($remoteStatus === null) {
            throw new \RuntimeException('Google campaign status is unavailable; conversion tracking remains unverified.');
        }
        $state['campaign_status'] = $remoteStatus;
        if ($remoteStatus !== 'ENABLED') {
            return $this->save($strategy, array_merge($state, ['status' => 'inactive',
                'message' => "Google reports this campaign as {$remoteStatus}; no zero-conversion alert was raised."]));
        }

        $readiness = $this->inspectGoals($strategy, $resource);
        if (($readiness['status'] ?? 'unknown') === 'unknown') {
            throw new \RuntimeException('Conversion goal evidence is unavailable; tracking remains unverified.');
        }
        $state['configuration_status'] = $readiness['status'];
        $state['intent'] = $readiness['intent'] ?? null;
        $state['issues'] = array_map(fn (AgentIssue $issue) => $issue->toArray(), AgentIssue::list($readiness['issues'] ?? []));
        if (! ($readiness['ready'] ?? false)) {
            return $this->save($strategy, array_merge($state, ['status' => 'needs_review',
                'message' => 'The selected conversion goal needs review: '.AgentIssue::toSentence($state['issues'])]));
        }

        $action = $readiness['intent']['action_resource'] ?? null;
        if (! is_string($action) || $action === '') {
            throw new \RuntimeException('The selected conversion action is unavailable; tracking remains unverified.');
        }
        $activity = $tracking->getCampaignActionConversionsLast30Days($customerId, $resource, $action);
        $state['selected_action_activity'] = $activity;
        $state['delivery'] = $this->delivery($customerId, $action, $readiness['intent']['category'] ?? null);
        $failures = ($state['delivery']['statuses']['upload_failed'] ?? 0)
            + ($state['delivery']['statuses']['processing_failed'] ?? 0);
        if ($failures > 0) {
            $state['issues'] = [(new AgentIssue('selected_conversion_delivery_failed',
                "{$failures} real server-side event(s) failed upload or processing for the selected conversion action."))->toArray()];

            return $this->save($strategy, array_merge($state, ['status' => 'delivery_failed',
                'message' => 'Google conversion delivery failed for recorded events. Review server-side delivery and the selected action; a GTM change alone will not repair an import failure.']));
        }
        if ($activity['all_conversions'] > 0) {
            return $this->save($strategy, array_merge($state, ['status' => 'conversions_observed',
                'message' => 'Google reports attributed conversions for this campaign’s selected action. Configuration and delivery evidence are recorded separately.']));
        }

        return $this->save($strategy, array_merge($state, ['status' => 'not_verified',
            'message' => 'The selected goal configuration is ready, but Google has not reported conversions for this campaign’s selected action in the last 30 days. This does not establish a tracking fault.']));
    }

    protected function inspectGoals(Strategy $strategy, string $resource): array
    {
        return (new ReconcileCampaignConversionGoals($strategy->campaign->customer))->inspect($strategy, $resource);
    }

    private function delivery(string $customerId, string $action, ?string $category): ?array
    {
        if ($customerId !== (string) config('conversions.google_ads_customer_id')
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/conversionActions/\d+$#', $action)) {
            return null;
        }
        // The selected, remotely inspected goal and each receipt's pinned
        // destination are evidence. A changed global mapping must not erase
        // an earlier upload failure for this exact account/action.
        $events = match ($action) {
            Setting::get('conversion_resource_name.paid_setup') => ['paid_setup'],
            // Older setup receipts deliberately retain this destination. Keep
            // observing them here even after new checkouts use a separate action.
            Setting::get('conversion_resource_name.paid_subscription') => ['paid_subscription', 'paid_setup'],
            Setting::get('conversion_resource_name.signup_import') => ['signup'],
            default => match ($category) {
                'SIGNUP' => ['signup'],
                'PURCHASE' => ['paid_subscription', 'paid_setup'],
                default => null,
            },
        };
        if ($events === null) {
            return null;
        }
        $statuses = [];
        $records = SpectraConversionEvent::whereIn('event', $events)->where('mode', 'server_google')
            ->where('google_conversion_resource', $action)->where('created_at', '>=', now()->subDays(30))
            ->orderBy('id')->lazyById(200);
        foreach ($records as $record) {
            // Organic registrations and legacy rows do not prove an attributable upload was expected.
            if (! $record->hasGoogleClickIdentifier() || ! $record->occurred_at) {
                continue;
            }
            $status = $record->googleDeliveryStatus();
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }

        return ['event' => count($events) === 1 ? $events[0] : 'purchase', 'events' => $events,
            'action_resource' => $action, 'days' => 30, 'statuses' => $statuses,
            'reported_in_google_ads' => 'not_verified'];
    }

    private function save(Strategy $strategy, array $state): array
    {
        // Merge after the remote reads; other agents may have updated deployment metadata meanwhile.
        DB::transaction(function () use ($strategy, $state) {
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            if ($locked->campaign_id !== $strategy->campaign_id
                || $locked->campaign->customer_id !== $strategy->campaign->customer_id
                || $locked->campaign->customer->cleanGoogleCustomerId() !== $strategy->campaign->customer->cleanGoogleCustomerId()
                || $locked->reusableGoogleCampaignId() !== $strategy->reusableGoogleCampaignId()
                || $locked->conversion_goals !== $strategy->conversion_goals) {
                throw new \RuntimeException('Campaign conversion intent changed during verification; retry with fresh evidence.');
            }
            $execution = $locked->execution_result ?? [];
            $execution['metadata']['conversion_tracking_health'] = $state;
            $locked->forceFill(['execution_result' => $execution])->save();
        }, 3);

        return $state;
    }
}
