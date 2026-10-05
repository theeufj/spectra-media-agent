<?php

namespace App\Support;

use Google\Ads\GoogleAds\V22\Common\PolicyTopicEntry;
use Google\Ads\GoogleAds\V22\Enums\PolicyTopicEvidenceDestinationNotWorkingDeviceEnum\PolicyTopicEvidenceDestinationNotWorkingDevice;
use Google\Ads\GoogleAds\V22\Enums\PolicyTopicEvidenceDestinationNotWorkingDnsErrorTypeEnum\PolicyTopicEvidenceDestinationNotWorkingDnsErrorType;

/** Keeps Google's policy evidence intact from the API through diagnostics and alerts. */
class GoogleAdPolicy
{
    public static function topic(PolicyTopicEntry $entry): array
    {
        $evidences = [];
        foreach ($entry->getEvidences() as $evidence) {
            $type = $evidence->getValue();
            $detail = ['type' => $type];
            if ($evidence->hasDestinationNotWorking()) {
                $destination = $evidence->getDestinationNotWorking();
                $detail += [
                    'expanded_url' => $destination->getExpandedUrl(),
                    'device' => self::enumName(PolicyTopicEvidenceDestinationNotWorkingDevice::class, $destination->getDevice()),
                    'device_code' => $destination->getDevice(),
                    'last_checked_at' => $destination->getLastCheckedDateTime(),
                    'http_error_code' => $destination->hasHttpErrorCode() ? (int) $destination->getHttpErrorCode() : null,
                    'dns_error_type' => $destination->hasDnsErrorType()
                        ? self::enumName(PolicyTopicEvidenceDestinationNotWorkingDnsErrorType::class, $destination->getDnsErrorType()) : null,
                    'dns_error_type_code' => $destination->hasDnsErrorType() ? $destination->getDnsErrorType() : null,
                ];
            } else {
                // Other evidence types (text, URL mismatch, websites) must not vanish either.
                $detail['data'] = json_decode($evidence->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
            }
            $evidences[] = $detail;
        }

        $constraints = [];
        foreach ($entry->getConstraints() as $constraint) {
            $constraints[] = json_decode($constraint->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return ['topic' => $entry->getTopic(), 'type' => $entry->getType(), 'evidences' => $evidences, 'constraints' => $constraints];
    }

    public static function isDestinationIssue(array $ad): bool
    {
        foreach ($ad['policy_topics'] ?? [] as $topic) {
            $name = strtoupper(str_replace([' ', '-'], '_', $topic['topic'] ?? ''));
            if (str_contains($name, 'DESTINATION') || $name === 'SITE_NOT_WORKING') {
                return true;
            }
            foreach ($topic['evidences'] ?? [] as $evidence) {
                if (in_array($evidence['type'] ?? '', ['destination_not_working', 'destination_mismatch'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function summarize(array $ad): string
    {
        $messages = [];
        foreach ($ad['policy_topics'] ?? [] as $topic) {
            $message = $topic['topic'] ?? 'Unknown policy violation';
            foreach ($topic['evidences'] ?? [] as $evidence) {
                if (($evidence['type'] ?? '') !== 'destination_not_working') {
                    continue;
                }
                $parts = [];
                if (! empty($evidence['http_error_code'])) {
                    $parts[] = 'HTTP '.$evidence['http_error_code'];
                }
                if (! empty($evidence['dns_error_type'])) {
                    $parts[] = 'DNS '.$evidence['dns_error_type'];
                }
                if (! empty($evidence['device']) && ! in_array($evidence['device'], ['UNKNOWN', 'UNSPECIFIED'], true)) {
                    $parts[] = 'device '.$evidence['device'];
                }
                if (! empty($evidence['expanded_url'])) {
                    $parts[] = 'URL '.$evidence['expanded_url'];
                }
                if (! empty($evidence['last_checked_at'])) {
                    $parts[] = 'Google checked '.$evidence['last_checked_at'];
                }
                if ($parts) {
                    $message .= ' ('.implode('; ', $parts).')';
                }
            }
            $messages[] = $message;
        }

        return $messages ? implode(', ', array_unique($messages)) : 'Unknown policy violation';
    }

    public static function details(array $ad): array
    {
        return [
            'ad_resource_name' => $ad['resource_name'] ?? null,
            'ad_group_resource_name' => $ad['ad_group_resource_name'] ?? null,
            'status' => $ad['status'] ?? null,
            'ad_group_status' => $ad['ad_group_status'] ?? null,
            'approval_status' => $ad['approval_status'] ?? null,
            'review_status' => $ad['review_status'] ?? null,
            'final_urls' => $ad['final_urls'] ?? [],
            'policy_topics' => $ad['policy_topics'] ?? [],
            'destination_issue' => self::isDestinationIssue($ad),
        ];
    }

    private static function enumName(string $enum, int $value): string
    {
        try {
            return $enum::name($value);
        } catch (\UnexpectedValueException) {
            return 'UNKNOWN';
        }
    }
}
