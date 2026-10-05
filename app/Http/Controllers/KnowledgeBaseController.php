<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlPage;
use App\Jobs\DiscoverKnowledgeSources;
use App\Jobs\ExtractBrandGuidelines;
use App\Jobs\IndexKnowledgeBase;
use App\Jobs\ProcessKnowledgeBaseFile;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeImport;
use App\Rules\SafePublicUrl;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use App\Services\KnowledgeBase\KnowledgeHealth;
use App\Services\KnowledgeBaseSearchService;
use App\Services\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request, KnowledgeHealth $health)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('quick-start');
        }
        $knowledgeHealth = $health->forCustomer($customer->id);
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'status' => 'nullable|string|in:ready,pending,needs_attention,failed,excluded']);
        $sources = KnowledgeBase::where('customer_id', $customer->id);
        if ($filters['q'] ?? null) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $sources->where(fn ($query) => $query->where('title', 'ilike', $like)->orWhere('url', 'ilike', $like)->orWhere('original_filename', 'ilike', $like));
        }
        if (($filters['status'] ?? '') === 'excluded') {
            $sources->whereNotNull('excluded_at');
        } elseif ($filters['status'] ?? null) {
            $sources->whereNull('excluded_at');
            ($filters['status'] === 'pending') ? $sources->whereIn('processing_status', ['queued', 'reading', 'indexing']) : $sources->where('processing_status', $filters['status']);
        }

        return Inertia::render('KnowledgeBase/Index', [
            'knowledgeBases' => $sources->latest('updated_at')->select([
                'id', 'title', 'url', 'source_type', 'original_filename', 'processing_status', 'processing_error',
                'excluded_at', 'fetched_at', 'indexed_at', 'source_version', 'created_at', 'updated_at',
            ])->selectRaw('left(content, 180) as preview')->paginate(15)->withQueryString(),
            'customer' => $customer->only('id', 'name', 'website'), 'health' => $knowledgeHealth,
            'imports' => $this->imports($customer->id), 'filters' => $filters,
            'sourceLimit' => $this->sourceLimit($customer),
            'brandProfile' => $customer->brandGuideline()->first(),
        ]);
    }

    public function create(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('quick-start');
        }

        return Inertia::render('KnowledgeBase/Create', ['customer' => $customer->only('id', 'name', 'website'), 'sourceLimit' => $this->sourceLimit($customer), 'sourceCount' => KnowledgeBase::where('customer_id', $customer->id)->whereNull('excluded_at')->count()]);
    }

    public function store(Request $request, KnowledgeBaseIndexer $indexer)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('quick-start');
        }
        $this->authorize('create', KnowledgeBase::class);
        $mode = $request->input('mode', $request->hasFile('document') ? 'document' : 'website');
        if ($mode === 'website') {
            $this->checkLimit($customer, 1);
            $data = $request->validate(['website_url' => ['required_without:sitemap_url', 'nullable', 'url', new SafePublicUrl], 'sitemap_url' => ['required_without:website_url', 'nullable', 'url', new SafePublicUrl]]);
            $import = KnowledgeImport::create(['customer_id' => $customer->id, 'user_id' => $request->user()->id, 'website_url' => $data['website_url'] ?? $data['sitemap_url']]);
            try {
                Bus::dispatch(new DiscoverKnowledgeSources($import));
            } catch (\Throwable $e) {
                report($e);
                $import->update(['status' => 'failed', 'error' => 'Page discovery could not start. Your address is saved. Try again, add an individual page, or write a business note.']);

                return redirect()->route('knowledge-base.index')->with('flash', ['type' => 'error', 'message' => $import->error]);
            }

            return redirect()->route('knowledge-base.index')->with('success', 'Finding public pages. You can choose which ones to include before we read them.');
        }
        if ($mode === 'page') {
            $data = $request->validate(['page_url' => ['required', 'url', new SafePublicUrl]]);
            $source = $this->addSource($customer, ['url' => $data['page_url']], ['user_id' => $request->user()->id, 'source_type' => 'url', 'content' => '']);
            $source->update(['excluded_at' => null, 'processing_status' => 'queued']);
            $queued = $this->queueSource($source, fn () => Bus::dispatch(new CrawlPage($request->user(), $source->url, $customer->id)));
        } elseif ($mode === 'note') {
            $data = $request->validate(['title' => 'required|string|max:255', 'content' => 'required|string|max:100000']);
            $source = $this->addSource($customer, ['url' => 'note:'.\Illuminate\Support\Str::uuid()], ['user_id' => $request->user()->id, 'source_type' => 'text', 'title' => $data['title'], 'content' => '']);
            $source = $indexer->prepare($source, $data['content']);
            $queued = $this->queueSource($source, fn () => Bus::dispatch(new IndexKnowledgeBase($source, $source->source_version)));
            ExtractBrandGuidelines::dispatch($customer, force: true, sourceRefresh: true);
        } elseif ($mode === 'document') {
            $request->validate(['document' => 'required|file|mimes:pdf,txt|max:10240']);
            $this->checkLimit($customer, 1);
            $file = $request->file('document');
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                throw ValidationException::withMessages(['document' => 'Choose a PDF or text file.']);
            }
            [$path, $url] = $this->storeDocument($file, $customer->id);
            try {
                $source = $this->addSource($customer, ['url' => $url], ['user_id' => $request->user()->id,
                    'file_path' => $path, 'source_type' => $file->getMimeType() === 'application/pdf' ? 'pdf' : 'text',
                    'title' => mb_substr($file->getClientOriginalName(), 0, 255), 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'content' => '']);
            } catch (\Throwable $e) {
                $this->deleteDocument($path);
                throw $e;
            }
            $queued = $this->queueSource($source, fn () => Bus::dispatch(new ProcessKnowledgeBaseFile($source)));
        } else {
            throw ValidationException::withMessages(['mode' => 'Choose a website, page, document, or note.']);
        }

        return redirect()->route('knowledge-base.show', $source)->with('flash', ['type' => $queued ? 'success' : 'error', 'message' => $queued ? 'Source added. Its reading and preparation status will update here. Existing ads keep their approved content.' : 'Your source is saved, but processing could not start. Retry from source details.']);
    }

    public function show(Request $request, KnowledgeBase $knowledgeBase, KnowledgeHealth $health)
    {
        $this->authorize('view', $knowledgeBase);
        if ($knowledgeBase->customer_id) {
            $health->reconcile($knowledgeBase->customer_id);
            $knowledgeBase->refresh();
        }
        $version = (int) $request->input('version', $knowledgeBase->source_version);
        abort_unless($version >= 1 && $version <= $knowledgeBase->source_version, 404);
        $passages = $knowledgeBase->chunks()->where('source_version', $version)->orderBy('position')->get(['position', 'content', 'embedding_model']);
        $retrievals = DB::table('knowledge_retrievals')->where('knowledge_base_id', $knowledgeBase->id)->where('customer_id', $knowledgeBase->customer_id)->latest('retrieved_at')->limit(20)->get();

        return Inertia::render('KnowledgeBase/Show', [
            'source' => $knowledgeBase, 'customer' => $knowledgeBase->customer?->only('id', 'name'),
            'version' => $version, 'versions' => $knowledgeBase->chunks()->distinct()->orderByDesc('source_version')->pluck('source_version'),
            'passages' => $passages, 'retrievals' => $retrievals,
            'editableNote' => $knowledgeBase->source_type === 'text' && ! $knowledgeBase->file_path,
            'fileRevision' => hash('sha256', $knowledgeBase->file_path ?? ''),
        ]);
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase, KnowledgeBaseIndexer $indexer)
    {
        $this->authorize('update', $knowledgeBase);
        $data = $request->validate(['title' => 'sometimes|required|string|max:255', 'included' => 'sometimes|boolean', 'content' => 'sometimes|required|string|max:100000', 'source_version' => 'required|integer']);
        DB::transaction(function () use ($data, $knowledgeBase, $indexer) {
            \App\Models\Customer::whereKey($knowledgeBase->customer_id)->lockForUpdate()->firstOrFail();
            $source = KnowledgeBase::whereKey($knowledgeBase->id)->lockForUpdate()->firstOrFail();
            if ($source->source_version !== (int) $data['source_version']) {
                throw ValidationException::withMessages(['content' => 'This source changed while you were editing. Reload and review its latest version.']);
            }
            if (array_key_exists('content', $data)) {
                abort_unless($source->source_type === 'text' && ! $source->file_path, 422);
                $source = $indexer->prepare($source, $data['content'], $data['title'] ?? null);
                IndexKnowledgeBase::dispatch($source, $source->source_version)->afterCommit();
            }
            if (($data['included'] ?? false) && $source->excluded_at) {
                $this->checkLimit(\App\Models\Customer::findOrFail($source->customer_id), 1);
            }
            $source->update([
                'title' => $data['title'] ?? $source->title,
                'excluded_at' => array_key_exists('included', $data) ? ($data['included'] ? null : now()) : $source->excluded_at,
            ]);
            if (array_key_exists('included', $data) && $data['included'] && $source->processing_status !== 'ready') {
                $source->update(['processing_status' => 'indexing', 'processing_error' => null]);
                IndexKnowledgeBase::dispatch($source, $source->source_version)->afterCommit();
            }
        });
        if ($knowledgeBase->customer) {
            ExtractBrandGuidelines::dispatch($knowledgeBase->customer, force: true, sourceRefresh: true);
        }

        return back()->with('success', 'Source updated. Review the proposed business-profile changes before approving them.');
    }

    public function retry(Request $request, KnowledgeBase $knowledgeBase)
    {
        $this->authorize('update', $knowledgeBase);
        $knowledgeBase = DB::transaction(function () use ($knowledgeBase, $request) {
            $source = KnowledgeBase::whereKey($knowledgeBase->id)->lockForUpdate()->firstOrFail();
            if ($source->excluded_at) {
                throw ValidationException::withMessages(['source' => 'Include this source before refreshing it.']);
            }
            $indexOnly = $request->boolean('index_only') || (! $source->file_path && $source->source_type === 'text');
            $source->update(['processing_status' => $indexOnly ? 'indexing' : 'queued', 'processing_error' => null]);

            return $source;
        });
        if ($request->boolean('index_only') || (! $knowledgeBase->file_path && $knowledgeBase->source_type === 'text')) {
            $queued = $this->queueSource($knowledgeBase, fn () => Bus::dispatch(new IndexKnowledgeBase($knowledgeBase, $knowledgeBase->source_version)));
        } elseif ($knowledgeBase->file_path) {
            $queued = $this->queueSource($knowledgeBase, fn () => Bus::dispatch(new ProcessKnowledgeBaseFile($knowledgeBase)));
        } else {
            $queued = $this->queueSource($knowledgeBase, fn () => Bus::dispatch(new CrawlPage($request->user(), $knowledgeBase->url, $knowledgeBase->customer_id)));
        }

        return back()->with('flash', ['type' => $queued ? 'success' : 'error', 'message' => $queued ? 'Refresh queued. Previous versions remain available in the source history.' : 'Processing could not start. Your source is saved; retry from source details.']);
    }

    public function replace(Request $request, KnowledgeBase $knowledgeBase)
    {
        $this->authorize('update', $knowledgeBase);
        $data = $request->validate(['document' => 'required|file|mimes:pdf,txt|max:10240', 'source_version' => 'required|integer', 'file_revision' => 'required|string|size:64'], ['file_revision.required' => 'Reload this source before replacing its file.']);
        abort_unless($knowledgeBase->file_path !== null && $knowledgeBase->source_type !== 'url', 422, 'Only an uploaded document can be replaced.');
        $file = $request->file('document');
        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            throw ValidationException::withMessages(['document' => 'Choose a PDF or text file.']);
        }
        [$path, $url] = $this->storeDocument($file, $knowledgeBase->customer_id);
        try {
            [$source, $oldPath] = DB::transaction(function () use ($knowledgeBase, $data, $file, $path, $url) {
                $source = KnowledgeBase::whereKey($knowledgeBase->id)->lockForUpdate()->firstOrFail();
                if ($source->source_version !== (int) $data['source_version'] || ! hash_equals(hash('sha256', $source->file_path ?? ''), $data['file_revision'])) {
                    throw ValidationException::withMessages(['document' => 'This source changed. Review its current version before replacing it.']);
                }
                $oldPath = $source->file_path;
                $source->update(['file_path' => $path, 'url' => $url, 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'source_type' => $file->getMimeType() === 'application/pdf' ? 'pdf' : 'text', 'processing_status' => 'queued', 'processing_error' => null]);

                return [$source, $oldPath];
            });
        } catch (\Throwable $e) {
            $this->deleteDocument($path);
            throw $e;
        }
        $queued = $this->queueSource($source, fn () => Bus::dispatch(new ProcessKnowledgeBaseFile($source)));
        if ($oldPath) {
            $this->deleteDocument($oldPath);
        }

        return back()->with('flash', ['type' => $queued ? 'success' : 'error', 'message' => $queued ? 'Replacement uploaded. Previous readable passages remain available while the new file is read.' : 'Your replacement is saved, but reading could not start. Previous readable passages remain available; retry from source details.']);
    }

    public function destroy(KnowledgeBase $knowledgeBase)
    {
        $this->authorize('delete', $knowledgeBase);
        if ($knowledgeBase->file_path) {
            StorageHelper::delete($knowledgeBase->file_path);
        }
        $customer = $knowledgeBase->customer;
        $knowledgeBase->delete();
        if ($customer) {
            ExtractBrandGuidelines::dispatch($customer, force: true, sourceRefresh: true);
        }

        return redirect()->route('knowledge-base.index')->with('success', 'Source deleted. Review any resulting business-profile changes. Existing ads are unchanged.');
    }

    public function search(Request $request, KnowledgeBaseSearchService $search)
    {
        $data = $request->validate(['query' => 'required|string|max:1000']);
        $customer = $this->getActiveCustomer($request);
        abort_unless($customer !== null, 404);

        return response()->json(['results' => $search->passages($customer->id, $data['query']), 'customer_id' => $customer->id]);
    }

    public function status(Request $request, KnowledgeHealth $health)
    {
        $customer = $this->getActiveCustomer($request);
        abort_unless($customer !== null, 404);

        return response()->json(['health' => $health->forCustomer($customer->id), 'imports' => $this->imports($customer->id)]);
    }

    public function startImport(Request $request, KnowledgeImport $knowledgeImport)
    {
        $this->authorize('update', $knowledgeImport);
        $data = $request->validate(['urls' => 'required|array|min:1|max:100', 'urls.*' => 'required|string']);
        $urls = array_values(array_unique($data['urls']));
        $jobs = DB::transaction(function () use ($knowledgeImport, $request, $urls) {
            $import = KnowledgeImport::whereKey($knowledgeImport->id)->lockForUpdate()->firstOrFail();
            if ($import->status !== 'review') {
                throw ValidationException::withMessages(['urls' => 'This import has already started. Refresh to see its source statuses.']);
            }
            $allowed = array_column($import->candidates ?? [], 'url');
            if (array_diff($urls, $allowed)) {
                throw ValidationException::withMessages(['urls' => 'Choose pages from this import preview.']);
            }
            $customer = \App\Models\Customer::whereKey($import->customer_id)->lockForUpdate()->firstOrFail();
            $newCount = count($urls) - KnowledgeBase::where('customer_id', $customer->id)->whereNull('excluded_at')->whereIn('url', $urls)->count();
            $this->checkLimit($customer, $newCount);
            $jobs = [];
            foreach ($urls as $url) {
                $source = KnowledgeBase::firstOrCreate(['customer_id' => $customer->id, 'url' => $url], ['user_id' => $request->user()->id, 'source_type' => 'url', 'content' => '']);
                $source->update(['excluded_at' => null, 'processing_status' => 'queued', 'processing_error' => null]);
                $jobs[] = new CrawlPage($request->user(), $url, $customer->id);
            }
            $import->update(['status' => 'processing', 'selected_urls' => $urls]);

            return $jobs;
        });
        $customer = \App\Models\Customer::findOrFail($knowledgeImport->customer_id);
        try {
            \Illuminate\Support\Facades\Bus::batch($jobs)->name('Knowledge import '.$knowledgeImport->id)
                ->allowFailures()->finally(function () use ($customer) {
                    ExtractBrandGuidelines::dispatch($customer, force: true, sourceRefresh: true);
                })->dispatch();
        } catch (\Throwable $e) {
            report($e);
            $knowledgeImport->update(['status' => 'failed', 'error' => 'Selected pages were saved, but reading could not start. Retry the affected sources below.']);
            KnowledgeBase::where('customer_id', $customer->id)->whereIn('url', $urls)->where('processing_status', 'queued')
                ->update(['processing_status' => 'failed', 'processing_error' => 'Reading could not start. Retry from source details.']);

            return back()->withErrors(['urls' => 'Reading could not start. Selected pages are retained; retry from source details.']);
        }
        Cache::forget('crawl:budget:'.$customer->id);

        return back()->with('success', 'Reading selected pages. Excluded pages will not be used for AI retrieval.');
    }

    private function imports(int $customerId)
    {
        $imports = KnowledgeImport::where('customer_id', $customerId)->latest()->limit(5)->get();
        foreach ($imports as $import) {
            if ($import->status === 'processing') {
                $sources = KnowledgeBase::where('customer_id', $customerId)->whereIn('url', $import->selected_urls ?? [])->get(['processing_status', 'excluded_at']);
                if ($sources->isEmpty() || $sources->every(fn ($source) => $source->excluded_at || in_array($source->processing_status, ['ready', 'failed', 'needs_attention'], true))) {
                    $import->update(['status' => $sources->every(fn ($source) => $source->processing_status === 'failed') ? 'failed' : 'completed']);
                }
            }
        }

        return $imports;
    }

    private function addSource(\App\Models\Customer $customer, array $key, array $values): KnowledgeBase
    {
        return DB::transaction(function () use ($customer, $key, $values) {
            \App\Models\Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $source = KnowledgeBase::where('customer_id', $customer->id)->where($key)->first();
            if (! $source || $source->excluded_at) {
                $this->checkLimit($customer, 1);
            }
            $source ??= KnowledgeBase::create(['customer_id' => $customer->id, ...$key, ...$values]);
            if ($source->excluded_at) {
                $source->update(['excluded_at' => null]);
            }

            return $source->refresh();
        });
    }

    private function sourceLimit(\App\Models\Customer $customer): ?int
    {
        return $customer->isOnPaidPlan() ? null : 3;
    }

    private function storeDocument(\Illuminate\Http\UploadedFile $file, int $customerId): array
    {
        // MIME validation permits plain text under any client filename. Never
        // copy an executable client extension into the public storage path.
        $extension = $file->getMimeType() === 'application/pdf' ? 'pdf' : 'txt';
        $path = 'knowledge-base/'.$customerId.'/'.\Illuminate\Support\Str::uuid().'.'.$extension;
        try {
            $contents = file_get_contents($file->getRealPath());
            if ($contents === false) {
                throw new \RuntimeException('Uploaded document could not be read.');
            }
            $stored = StorageHelper::put($path, $contents, $file->getMimeType());
            if (! StorageHelper::exists($path)) {
                throw new \RuntimeException('Uploaded document was not saved to storage.');
            }

            return $stored;
        } catch (\Throwable $e) {
            report($e);
            $this->deleteDocument($path);
            throw ValidationException::withMessages(['document' => 'The file could not be saved. Your existing sources are unchanged. Try uploading again.']);
        }
    }

    private function deleteDocument(string $path): void
    {
        try {
            StorageHelper::delete($path, true);
            if (StorageHelper::exists($path)) {
                throw new \RuntimeException('Document cleanup did not finish.');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function queueSource(KnowledgeBase $source, \Closure $dispatch): bool
    {
        try {
            $dispatch();

            return true;
        } catch (\Throwable $e) {
            report($e);
            KnowledgeBase::whereKey($source->id)->where('source_version', $source->source_version)
                ->where('file_path', $source->file_path)->whereNull('excluded_at')->update([
                    'processing_status' => 'failed',
                    'processing_error' => 'Processing could not start. Your source and any readable text are saved. Retry from source details.',
                ]);

            return false;
        }
    }

    private function checkLimit(\App\Models\Customer $customer, int $additional): void
    {
        $limit = $this->sourceLimit($customer);
        if ($limit && KnowledgeBase::where('customer_id', $customer->id)->whereNull('excluded_at')->count() + $additional > $limit) {
            throw ValidationException::withMessages(['sources' => 'Your plan includes 3 active sources. Exclude a source or upgrade before adding more.']);
        }
    }
}
