<?php

namespace App\Services\Competition;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Cache;

class CompetitiveActionReport
{
    public function forCustomer(Customer $customer): array
    {
        $actions = Recommendation::whereIn('campaign_id', Campaign::where('customer_id', $customer->id)->select('id'))
            ->where('source', 'competitor')->with('campaign')->latest()->limit(30)->get()->map(fn ($rec) => [
                'id' => $rec->id, 'campaign_name' => $rec->campaign?->name, 'campaign_uuid' => $rec->campaign?->uuid,
                'type' => $rec->type, 'status' => $rec->status, 'rationale' => $rec->rationale,
                'currency' => $customer->currency_code,
                'change' => $rec->parameters, 'can_apply' => $rec->execution['can_apply'] ?? false,
                'message' => $rec->execution['error'] ?? $rec->execution['blocked_reason']
                    ?? ((! ($rec->execution['result']['applied'] ?? true)) ? ($rec->execution['result']['message'] ?? null) : null),
                'evidence' => collect($rec->evidence ?? [])->map(fn ($source) => [
                    'id' => $source['id'] ?? null, 'kind' => $source['kind'] ?? null,
                    'observed_at' => $source['observed_at'] ?? null, 'domain' => $source['data']['domain'] ?? null,
                ])->all(),
                'created_at' => $rec->created_at->toIso8601String(), 'applied_at' => $rec->applied_at?->toIso8601String(),
                'verified_at' => $rec->verified_at?->toIso8601String(), 'outcome' => $rec->outcome,
            ])->values()->all();

        return ['actions' => $actions, 'review' => Cache::get('competitive_review:'.$customer->id)];
    }
}
