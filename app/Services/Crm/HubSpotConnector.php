<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotConnector implements CrmConnectorInterface
{
    protected string $accessToken;
    protected string $baseUrl = 'https://api.hubapi.com';

    public function __construct(array $credentials)
    {
        $this->accessToken = $credentials['access_token'] ?? '';
    }

    public function getProvider(): string
    {
        return 'hubspot';
    }

    public function testConnection(): bool
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->baseUrl}/crm/v3/objects/contacts", ['limit' => 1]);
            return $response->successful();
        } catch (\Exception $e) {
            Log::debug('HubSpot connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function fetchClosedLeads(\DateTimeInterface $since): array
    {
        $leads = [];
        $after = null;

        do {
            $params = [
                'limit' => 100,
                'properties' => 'dealname,amount,closedate,gclid,pipeline,dealstage,hs_analytics_source,hs_analytics_first_url',
                'filterGroups' => [[
                    'filters' => [
                        ['propertyName' => 'dealstage', 'operator' => 'EQ', 'value' => 'closedwon'],
                        ['propertyName' => 'closedate', 'operator' => 'GTE', 'value' => $since->getTimestamp() * 1000],
                    ],
                ]],
            ];

            if ($after) {
                $params['after'] = $after;
            }

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/crm/v3/objects/deals/search", $params);

            if (!$response->successful()) {
                Log::warning('HubSpot fetchClosedLeads failed', ['status' => $response->status()]);
                break;
            }

            $data = $response->json();
            foreach ($data['results'] ?? [] as $deal) {
                $props = $deal['properties'] ?? [];
                $firstUrl = $props['hs_analytics_first_url'] ?? '';

                // Extract click IDs from first URL
                $gclid = $this->extractParam($firstUrl, 'gclid') ?: ($props['gclid'] ?? null);
                $fbclid = $this->extractParam($firstUrl, 'fbclid');
                $msclid = $this->extractParam($firstUrl, 'msclid');

                $leads[] = [
                    'crm_lead_id' => (string) $deal['id'],
                    'conversion_name' => 'CRM Deal Won',
                    'conversion_value' => (float) ($props['amount'] ?? 0),
                    'conversion_time' => $props['closedate'] ? date('Y-m-d H:i:s', strtotime($props['closedate'])) : now()->toDateTimeString(),
                    'gclid' => $gclid,
                    'fbclid' => $fbclid,
                    'msclid' => $msclid,
                    'crm_data' => $props,
                ];
            }

            $after = $data['paging']['next']['after'] ?? null;
        } while ($after);

        return $leads;
    }

    public function getPipelineStages(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/crm/v3/pipelines/deals");

        if (!$response->successful()) return [];

        $stages = [];
        foreach ($response->json()['results'] ?? [] as $pipeline) {
            foreach ($pipeline['stages'] ?? [] as $stage) {
                $stages[] = [
                    'id' => $stage['id'],
                    'label' => $pipeline['label'] . ' > ' . $stage['label'],
                    'pipeline' => $pipeline['label'],
                ];
            }
        }
        return $stages;
    }

    protected function extractParam(string $url, string $param): ?string
    {
        if (!$url) return null;
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
        return $params[$param] ?? null;
    }
}
