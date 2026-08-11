<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\KeywordMutator;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Records what the agent decided to do instead of doing it.
 *
 * Returns a resource name shaped like Google's so the agent's success paths run
 * exactly as they would live — the decisions, logging and result assembly are
 * all real; only the network call is not. The "sandbox/" prefix makes it
 * obvious in any output that nothing was actually written to an ad account.
 */
class SandboxKeywordMutator implements KeywordMutator
{
    /** @var list<array{action: string, keyword: string, match_type: int, target: string}> */
    private array $recorded = [];

    public function __construct(private Customer $customer) {}

    public function addKeyword(string $customerId, string $adGroupResourceName, string $keyword, int $matchType): ?string
    {
        return $this->record('add_keyword', $keyword, $matchType, $adGroupResourceName);
    }

    public function addNegativeKeyword(string $customerId, string $campaignResourceName, string $keyword, int $matchType): ?string
    {
        return $this->record('add_negative', $keyword, $matchType, $campaignResourceName);
    }

    /** @return list<array{action: string, keyword: string, match_type: int, target: string}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    private function record(string $action, string $keyword, int $matchType, string $target): string
    {
        $this->recorded[] = [
            'action' => $action,
            'keyword' => $keyword,
            'match_type' => $matchType,
            'target' => $target,
        ];

        Log::info('SandboxKeywordMutator: recorded intended change (nothing sent)', [
            'customer_id' => $this->customer->id,
            'action' => $action,
            'keyword' => $keyword,
        ]);

        return 'sandbox/'.$action.'/'.md5($target.$keyword.$matchType);
    }
}
