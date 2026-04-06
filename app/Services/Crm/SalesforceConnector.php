<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesforceConnector implements CrmConnectorInterface
{
    protected string $accessToken;
    protected string $instanceUrl;

    public function __construct(array $credentials)
    {
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
        } catch (\Exception $e) {
            Log::debug('Salesforce connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function fetchClosedLeads(\DateTimeInterface $since): array
    {
        $sinceStr = $since->format('Y-m-d\TH:i:s\Z');
        $soql = "SELECT Id, Name, Amount, CloseDate, StageName, LeadSource, "
            . "GCLID__c, Description "
            . "FROM Opportunity "
            . "WHERE StageName = 'Closed Won' AND CloseDate >= {$sinceStr} "
            . "ORDER BY CloseDate DESC LIMIT 200";

        $response = Http::withToken($this->accessToken)
            ->get("{$this->instanceUrl}/services/data/v59.0/query", ['q' => $soql]);

        if (!$response->successful()) {
            Log::warning('Salesforce fetchClosedLeads failed', ['status' => $response->status()]);
            return [];
        }

        $leads = [];
        foreach ($response->json()['records'] ?? [] as $opp) {
            $leads[] = [
                'crm_lead_id' => $opp['Id'],
                'conversion_name' => 'CRM Opportunity Won',
                'conversion_value' => (float) ($opp['Amount'] ?? 0),
                'conversion_time' => $opp['CloseDate'] ? date('Y-m-d H:i:s', strtotime($opp['CloseDate'])) : now()->toDateTimeString(),
                'gclid' => $opp['GCLID__c'] ?? null,
                'fbclid' => null,
                'msclid' => null,
                'crm_data' => $opp,
            ];
        }

        return $leads;
    }

    public function getPipelineStages(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->instanceUrl}/services/data/v59.0/sobjects/Opportunity/describe");

        if (!$response->successful()) return [];

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
