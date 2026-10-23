<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest;
use Google\Protobuf\FieldMask;

/** A scoped bid-only adapter; its caller owns campaign authorization and readiness checks. */
class UpdateAdGroupCpcBid extends BaseGoogleAdsService
{
    /** The bid is integer micros in the Google account currency, aligned to whole cents. */
    public function __invoke(string $customerId, string $adGroupResource, int $bidMicros): bool
    {
        if (! preg_match('/^[1-9][0-9]{9}$/D', $customerId)
            || $this->customer?->cleanGoogleCustomerId() !== $customerId
            || ! preg_match('#^customers/'.$customerId.'/adGroups/[1-9][0-9]*$#D', $adGroupResource)
            || $bidMicros <= 0 || $bidMicros % 10_000 !== 0) {
            $this->logError('Ad group CPC bid refused: the account, resource or whole-cent bid is invalid.');

            return false;
        }

        try {
            $this->ensureClient();
            $operation = new AdGroupOperation([
                'update' => new AdGroup(['resource_name' => $adGroupResource, 'cpc_bid_micros' => $bidMicros]),
                'update_mask' => new FieldMask(['paths' => ['cpc_bid_micros']]),
            ]);
            $response = $this->client->getAdGroupServiceClient()->mutateAdGroups(new MutateAdGroupsRequest([
                'customer_id' => $customerId, 'operations' => [$operation],
                'partial_failure' => false, 'validate_only' => $this->dryRun,
            ]));
            if (($response->hasPartialFailureError() && $response->getPartialFailureError()->getCode() !== 0)
                || (! $this->dryRun && (count($response->getResults()) !== 1
                    || $response->getResults()[0]->getResourceName() !== $adGroupResource))) {
                throw new \UnexpectedValueException('Google did not confirm the requested ad group CPC bid mutation.');
            }
            $this->logInfo(($this->dryRun ? 'Validated' : 'Updated')." ad group CPC bid to {$bidMicros} micros: {$adGroupResource}");

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to update ad group CPC bid: '.$e->getMessage(), $e);

            return false;
        }
    }
}
