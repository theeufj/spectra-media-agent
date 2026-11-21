<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesforceConnector implements CrmConnectorInterface
{
    protected string $accessToken;

    protected string $instanceUrl;

    private ?string $consentField;

    private ?string $currency;

    public function __construct(array $credentials)
    {
        $field = $credentials['consent_field'] ?? '';
        $this->consentField = is_string($field) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D', $field) ? $field : null;
        $this->currency = $credentials['default_currency'] ?? null;
        $this->accessToken = $credentials['access_token'] ?? '';
        $this->instanceUrl = rtrim($credentials['instance_url'] ?? '', '/');
    }

    public function getProvider(): string
    {
        return 'salesforce';
    }

    public function testConnection(): bool
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->instanceUrl}/services/data/v59.0/sobjects/");

            return $response->successful();
        } catch (\Throwable $e) {
            report($e);
            Log::debug('Salesforce connection test failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function fetchClosedLeads(\DateTimeInterface $since): array
    {
        $api = $this->instanceUrl.'/services/data/v59.0';
        $client = Http::withToken($this->accessToken);
        $fields = $client->get($api.'/sobjects/Opportunity/describe')->throw()->json('fields');
        if (! is_array($fields)) {
            throw new \RuntimeException('Salesforce did not return the available opportunity fields.');
        }
        $optional = array_filter(['GCLID__c', 'FBCLID__c', 'CurrencyIsoCode', 'LastStageChangeDate', $this->consentField]);
        $columns = implode(', ', array_merge(['Id', 'IsWon', 'Amount', 'CloseDate'], array_intersect($optional, array_column($fields, 'name'))));
        $sinceUtc = \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $response = $client->get($api.'/query', ['q' => "SELECT {$columns} FROM Opportunity WHERE IsWon = TRUE AND LastModifiedDate >= {$sinceUtc} ORDER BY LastModifiedDate, Id"]);
        $leads = [];
        $visited = [];
        while (true) {
            $page = $response->throw()->json();
            if (! is_array($page['records'] ?? null) || ! is_bool($page['done'] ?? null)) {
                throw new \RuntimeException('Salesforce returned an incomplete opportunity page.');
            }
            foreach ($page['records'] as $opportunity) {
                if (($opportunity['IsWon'] ?? false) !== true || empty($opportunity['Id']) || empty($opportunity['CloseDate'])) {
                    continue;
                }
                $timestamp = $opportunity['LastStageChangeDate'] ?? null;
                $leads[] = [
                    'crm_lead_id' => (string) $opportunity['Id'], 'conversion_name' => 'CRM Opportunity Won',
                    'conversion_value' => $opportunity['Amount'] ?? null, 'currency_code' => $opportunity['CurrencyIsoCode'] ?? $this->currency,
                    'conversion_time' => $timestamp ?: $opportunity['CloseDate'], 'precise_event_time' => (bool) $timestamp,
                    'gclid' => $opportunity['GCLID__c'] ?? null, 'fbclid' => $opportunity['FBCLID__c'] ?? null,
                    'msclid' => null, 'ad_measurement_consent' => in_array($opportunity[$this->consentField ?? ''] ?? null, [true, 'true', 'granted'], true),
                    'crm_data' => ['close_date' => $opportunity['CloseDate']],
                ];
            }
            if ($page['done']) {
                break;
            }
            $next = $page['nextRecordsUrl'] ?? '';
            if (! is_string($next) || ! preg_match('~^/services/data/v59\.0/query/[A-Za-z0-9_-]+$~D', $next) || isset($visited[$next])) {
                throw new \RuntimeException('Salesforce did not return a valid next opportunity page.');
            }
            $visited[$next] = true;
            $response = $client->get($this->instanceUrl.$next);
        }

        return $leads;
    }

    public function getPipelineStages(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->instanceUrl}/services/data/v59.0/sobjects/Opportunity/describe");

        if (! $response->successful()) {
            return [];
        }

        $stages = [];
        foreach ($response->json()['fields'] ?? [] as $field) {
            if ($field['name'] === 'StageName') {
                foreach ($field['picklistValues'] ?? [] as $val) {
                    $stages[] = ['id' => $val['value'], 'label' => $val['label'], 'pipeline' => 'Default'];
                }
            }
        }

        return $stages;
    }
}
