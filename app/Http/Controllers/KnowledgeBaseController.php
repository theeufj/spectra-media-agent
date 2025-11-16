<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Jobs\ProcessKnowledgeBaseFile;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class KnowledgeBaseController extends Controller
{
    /**
     * Display a listing of all knowledge base entries for the authenticated user.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        $user = Auth::user();
        $knowledgeBases = $user->knowledgeBases()
            ->paginate(10);

        return Inertia::render('KnowledgeBase/Index', [
            'knowledgeBases' => $knowledgeBases,
        ]);
    }

    /**
     * create is the handler for showing the sitemap submission form.
     *
     * @return \Inertia\Response
     */
    public function create()
    {
        // Render the React component we created earlier.
        return Inertia::render('KnowledgeBase/Create');
    }

    /**
     * store is the handler for processing the sitemap submission or file upload.
     * It validates the request and dispatches the appropriate background job.
     *
     * @param Request $request The incoming HTTP request.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Check if a file is being uploaded
        if ($request->hasFile('document')) {
            return $this->handleFileUpload($request, $user);
        }

        // Otherwise, handle sitemap URL submission
        $validated = $request->validate([
            'sitemap_url' => 'required|url',
        ]);

        CrawlSitemap::dispatch($user, $validated['sitemap_url']);

        return redirect()->route('dashboard')->with('success', 'Sitemap submitted! We will start crawling your site shortly.');
    }

    /**
     * Handle file uploads (PDF or Text documents).
     */
    private function handleFileUpload(Request $request, $user)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,txt|max:10240', // Max 10MB
        ]);

        $file = $request->file('document');
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $sourceType = $extension === 'pdf' ? 'pdf' : 'text';

        // Generate a unique filename to avoid conflicts
        $filename = uniqid('kb_', true) . '.' . $extension;
        $s3Path = "knowledge-base/{$user->id}/{$filename}";

        try {
            \Log::info('Starting file upload', [
                'user_id' => $user->id,
                'original_name' => $originalName,
                'source_type' => $sourceType,
                's3_path' => $s3Path,
                'file_size' => $file->getSize(),
                'file_mime' => $file->getMimeType(),
            ]);

            // Verify S3 config
            $s3Config = config('filesystems.disks.s3');
            \Log::info('S3 Configuration', [
                'driver' => $s3Config['driver'] ?? null,
                'key' => substr($s3Config['key'] ?? '', 0, 10) . '***',
                'secret' => substr($s3Config['secret'] ?? '', 0, 10) . '***',
                'key_exists' => !empty($s3Config['key']),
                'secret_exists' => !empty($s3Config['secret']),
                'bucket' => $s3Config['bucket'] ?? null,
                'region' => $s3Config['region'] ?? null,
                'url' => $s3Config['url'] ?? null,
                'endpoint' => $s3Config['endpoint'] ?? null,
            ]);

            // Try uploading file to S3 with error capture
            try {
                // Get AWS S3 client directly for better error handling
                $s3Client = Storage::disk('s3')->getClient();
                
                \Log::info('S3 Client created', [
                    'user_id' => $user->id,
                ]);

                                // Upload using putObject (without ACL since bucket disables them)
                $result = $s3Client->putObject([
                    'Bucket' => env('AWS_BUCKET') ?: env('S3_BUCKET'),
                    'Key' => $s3Path,
                    'Body' => fopen($file->getRealPath(), 'r'),
                    'ContentType' => $file->getMimeType(),
                ]);

                \Log::info('S3 putObject result', [
                    'user_id' => $user->id,
                    'etag' => $result['ETag'] ?? null,
                    'object_url' => $result['ObjectURL'] ?? null,
                ]);

                if (!isset($result['ETag'])) {
                    \Log::error('S3 upload failed - no ETag in response', [
                        'user_id' => $user->id,
                        's3_path' => $s3Path,
                        'result' => $result,
                    ]);
                    return redirect()->back()->withErrors(['document' => 'Failed to upload file to S3. No ETag returned.']);
                }
            } catch (\Exception $uploadError) {
                \Log::error('S3 upload exception: ' . $uploadError->getMessage(), [
                    'user_id' => $user->id,
                    's3_path' => $s3Path,
                    'exception' => get_class($uploadError),
                    'code' => $uploadError->getCode(),
                    'message' => $uploadError->getMessage(),
                    'file' => $uploadError->getFile(),
                    'line' => $uploadError->getLine(),
                ]);
                return redirect()->back()->withErrors(['document' => 'S3 Error: ' . $uploadError->getMessage()]);
            }

            // Construct the CloudFront URL for accessing the file
            $cloudfrontDomain = config('filesystems.cloudfront_domain') ?: env('CLOUDFRONT_DOMAIN');
            $cloudFrontUrl = "{$cloudfrontDomain}/{$s3Path}";

            \Log::info('CloudFront URL constructed', [
                'user_id' => $user->id,
                'cloudfront_domain' => $cloudfrontDomain,
                'cloudfront_url' => $cloudFrontUrl,
            ]);

            // Create knowledge base entry
            $knowledgeBase = KnowledgeBase::create([
                'user_id' => $user->id,
                'url' => $cloudFrontUrl, // Store CloudFront URL
                'file_path' => $s3Path,
                'source_type' => $sourceType,
                'original_filename' => $originalName,
                'content' => '', // Will be filled by the job
            ]);

            \Log::info('Knowledge base entry created', [
                'user_id' => $user->id,
                'kb_id' => $knowledgeBase->id,
                'url' => $knowledgeBase->url,
            ]);

            // Dispatch job to extract content from file
            ProcessKnowledgeBaseFile::dispatch($knowledgeBase);

            \Log::info('File processing job dispatched', [
                'user_id' => $user->id,
                'kb_id' => $knowledgeBase->id,
            ]);

            return redirect()->route('dashboard')->with('success', 'File uploaded! We are processing your document and will extract the content shortly.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('File upload error: ' . $e->getMessage());
            return redirect()->back()->withErrors(['document' => 'Failed to upload file. Please try again.']);
        }
    }

    /**
     * Delete a knowledge base entry and remove the associated file from S3.
     *
     * @param KnowledgeBase $knowledgeBase The knowledge base entry to delete.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(KnowledgeBase $knowledgeBase)
    {
        $user = Auth::user();

        // Ensure the user can only delete their own knowledge base entries
        if ($knowledgeBase->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        try {
            // Delete file from S3 if it exists
            if ($knowledgeBase->file_path) {
                try {
                    $s3Client = Storage::disk('s3')->getClient();
                    $s3Client->deleteObject([
                        'Bucket' => env('AWS_BUCKET') ?: env('S3_BUCKET'),
                        'Key' => $knowledgeBase->file_path,
                    ]);

                    \Log::info('File deleted from S3', [
                        'user_id' => $user->id,
                        'kb_id' => $knowledgeBase->id,
                        's3_path' => $knowledgeBase->file_path,
                    ]);
                } catch (\Exception $s3Error) {
                    \Log::warning('Failed to delete file from S3: ' . $s3Error->getMessage(), [
                        'user_id' => $user->id,
                        'kb_id' => $knowledgeBase->id,
                    ]);
                    // Continue with deletion even if S3 deletion fails
                }
            }

            // Delete the database record
            $knowledgeBase->delete();

            \Log::info('Knowledge base entry deleted', [
                'user_id' => $user->id,
                'kb_id' => $knowledgeBase->id,
            ]);

            return redirect()->route('knowledge-base.index')->with('success', 'Knowledge base entry deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Failed to delete knowledge base entry: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to delete knowledge base entry.']);
        }
    }

    /**
     * Search through the user's knowledge base content.
     * Uses simple text matching to find relevant chunks.
     *
     * @param Request $request The incoming HTTP request with 'query' parameter.
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $user = Auth::user();
        $query = $request->input('query', '');

        if (empty($query)) {
            return response()->json(['results' => []]);
        }

        try {
            // Initialize Gemini Service
            $geminiService = new GeminiService();

            // Step 1: Generate embedding for the search query
            $queryEmbedding = $geminiService->embedContent('text-embedding-004', $query);

            if (is_null($queryEmbedding)) {
                Log::error("Failed to get embedding for search query: Query embedding was null.");
                return response()->json(['error' => 'Failed to process query'], 500);
            }

            // Step 2: Retrieve KnowledgeBase entries with content and embeddings
            $knowledgeBases = $user->knowledgeBases()
                ->where('content', '!=', '')
                ->whereNotNull('embedding')
                ->get();

            $results = [];

            // Step 3: Iterate through chunks and embeddings to find relevant ones
            foreach ($knowledgeBases as $kb) {
                $chunks = json_decode($kb->content, true);
                $embeddings = $kb->embedding->toArray();

                if (!is_array($chunks) || !is_array($embeddings) || count($chunks) !== count($embeddings)) {
                    Log::warning("KnowledgeBase ID {$kb->id} has malformed chunks or embeddings.");
                    continue;
                }

                foreach ($chunks as $index => $chunk) {
                    $chunkEmbedding = $embeddings[$index];

                    // Calculate cosine similarity
                    $similarity = $this->cosineSimilarity($queryEmbedding, $chunkEmbedding);
                    
                    if ($similarity > 0.7) { // Threshold for relevance (adjust as needed)
                        $results[] = [
                            'chunk' => trim($chunk),
                            'source_name' => $kb->original_filename ?: ($kb->url ?? 'Unknown Source'),
                            'similarity' => min($similarity, 1.0),
                            'kb_id' => $kb->id,
                        ];
                    }
                }
            }

            // Sort by similarity score (highest first)
            usort($results, function ($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });

            // Return top 10 results
            $results = array_slice($results, 0, 10);

            Log::info('Knowledge base semantic search executed', [
                'user_id' => $user->id,
                'query' => $query,
                'results_count' => count($results),
            ]);

            return response()->json(['results' => $results]);
        } catch (\Exception $e) {
            Log::error('Knowledge base semantic search error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return response()->json(['error' => 'Search failed'], 500);
        }
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $vectorA
     * @param array $vectorB
     * @return float
     */
    private function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $size = count($vectorA);
        for ($i = 0; $i < $size; $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $magnitudeA += $vectorA[$i] * $vectorA[$i];
            $magnitudeB += $vectorB[$i] * $vectorB[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0; // Avoid division by zero
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
}
