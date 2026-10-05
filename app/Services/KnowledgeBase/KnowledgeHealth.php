<?php

namespace App\Services\KnowledgeBase;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class KnowledgeHealth
{
    public function reconcile(int $customerId): void
    {
        KnowledgeBase::where('customer_id', $customerId)->whereNull('excluded_at')->where('processing_status', 'ready')
            ->where(fn ($query) => $query->whereNull('embedding_model')->orWhere('embedding_model', '!=', config('ai.models.embedding')))
            ->update(['processing_status' => 'needs_attention', 'processing_error' => 'Your readable text is available. This source needs preparation with the current AI search settings. Retry preparation from source details.']);
    }

    public function forCustomer(int $customerId): array
    {
        $this->reconcile($customerId);
        $sources = KnowledgeBase::where('customer_id', $customerId)->get([
            'id', 'source_version', 'excluded_at', 'processing_status', 'fetched_at', 'indexed_at',
            DB::raw('case when length(trim(content)) > 0 then 1 else 0 end as has_content'),
        ]);
        $selected = $sources->whereNull('excluded_at');
        $chunks = DB::table('knowledge_base_chunks as chunks')
            ->join('knowledge_bases as sources', 'sources.id', '=', 'chunks.knowledge_base_id')
            ->where('sources.customer_id', $customerId)->whereNull('sources.excluded_at')
            ->whereColumn('chunks.source_version', 'sources.source_version')
            ->selectRaw('count(*) as total, count(chunks.embedding) as indexed, count(case when chunks.embedding is not null and chunks.embedding_model = ? then 1 end) as primary_count', [config('ai.models.embedding')])
            ->first();

        return [
            'total' => $sources->count(), 'selected' => $selected->count(),
            'excluded' => $sources->whereNotNull('excluded_at')->count(),
            'readable' => $selected->filter(fn ($source) => $source->getAttribute('has_content') === 1)->count(),
            'ready' => $selected->where('processing_status', 'ready')->count(),
            'pending' => $selected->whereIn('processing_status', ['queued', 'reading', 'indexing'])->count(),
            'needs_attention' => $selected->where('processing_status', 'needs_attention')->count(),
            'failed' => $selected->where('processing_status', 'failed')->count(),
            'passages' => (int) ($chunks->total ?? 0), 'indexed_passages' => (int) ($chunks->indexed ?? 0),
            'primary_passages' => (int) ($chunks->primary_count ?? 0),
            'mismatched_passages' => (int) ($chunks->indexed ?? 0) - (int) ($chunks->primary_count ?? 0),
            'fetched_at' => $selected->max('fetched_at')?->toIso8601String(),
            'indexed_at' => $selected->max('indexed_at')?->toIso8601String(),
        ];
    }
}
