<?php

namespace App\Services\Testing;

class SyntheticDataService
{
    public function __invoke(int $days = 7): array
    {
        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $data[] = [
                'campaign_id' => 1,
                'campaign_name' => 'Test Campaign',
                'impressions' => rand(1000, 5000),
                'clicks' => rand(100, 500),
                'cost' => rand(50, 200),
                'conversions' => rand(5, 20),
            ];
        }
        return $data;
    }
}
