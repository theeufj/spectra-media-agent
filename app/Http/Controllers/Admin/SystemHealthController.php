<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SystemHealthController extends Controller
{
    /**
     * Display the system health dashboard.
     */
    public function index()
    {
        return Inertia::render('Admin/SystemHealth', [
            'health' => $this->getHealthData(),
        ]);
    }

    /**
     * Get health data via AJAX for refresh.
     */
    public function check()
    {
        return response()->json($this->getHealthData());
    }

    /**
     * Gather all health data.
     */
    private function getHealthData(): array
    {
        return [
            'apis' => $this->checkApiConnectivity(),
            'database' => $this->checkDatabase(),
            'queue' => $this->getQueueStats(),
            'storage' => $this->checkStorage(),
            'cache' => $this->checkCache(),
            'checkedAt' => now()->toISOString(),
        ];
    }

    /**
     * Check API connectivity for external services.
     */
    private function checkApiConnectivity(): array
    {
        $apis = [];

        // Google Ads API
        $apis['google_ads'] = $this->checkGoogleAds();

        // Facebook/Meta API
        $apis['facebook'] = $this->checkFacebook();

        // Gemini API
        $apis['gemini'] = $this->checkGemini();

        // Stripe API
        $apis['stripe'] = $this->checkStripe();

        // AWS S3
        $apis['aws_s3'] = $this->checkS3();

        return $apis;
    }

    /**
     * Check Google Ads API.
     */
    private function checkGoogleAds(): array
    {
        try {
            $hasCredentials = !empty(config('services.google.client_id')) && 
                              !empty(config('services.google.client_secret'));
            
            return [
                'name' => 'Google Ads',
                'status' => $hasCredentials ? 'configured' : 'not_configured',
                'message' => $hasCredentials ? 'API credentials configured' : 'Missing API credentials',
                'icon' => 'google',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Google Ads',
                'status' => 'error',
                'message' => $e->getMessage(),
                'icon' => 'google',
            ];
        }
    }

    /**
     * Check Facebook/Meta API.
     */
    private function checkFacebook(): array
    {
        try {
            $hasCredentials = !empty(config('services.facebook.client_id')) && 
                              !empty(config('services.facebook.client_secret'));
            
            return [
                'name' => 'Facebook/Meta',
                'status' => $hasCredentials ? 'configured' : 'not_configured',
                'message' => $hasCredentials ? 'API credentials configured' : 'Missing API credentials',
                'icon' => 'facebook',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Facebook/Meta',
                'status' => 'error',
                'message' => $e->getMessage(),
                'icon' => 'facebook',
            ];
        }
    }

    /**
     * Check Gemini API connectivity.
     */
    private function checkGemini(): array
    {
        try {
            $apiKey = config('services.gemini_api_key') ?: config('services.google.gemini_api_key');
            
            if (!$apiKey) {
                return [
                    'name' => 'Gemini AI',
                    'status' => 'not_configured',
                    'message' => 'API key not configured',
                    'icon' => 'sparkles',
                ];
            }

            // Quick connectivity test
            $response = Http::timeout(5)->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
            
            return [
                'name' => 'Gemini AI',
                'status' => $response->successful() ? 'healthy' : 'error',
                'message' => $response->successful() ? 'API responding' : 'API not responding',
                'icon' => 'sparkles',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Gemini AI',
                'status' => 'error',
                'message' => 'Connection timeout or error',
                'icon' => 'sparkles',
            ];
        }
    }

    /**
     * Check Stripe API connectivity.
     */
    private function checkStripe(): array
    {
        try {
            $hasCredentials = !empty(config('cashier.secret'));
            
            if (!$hasCredentials) {
                return [
                    'name' => 'Stripe',
                    'status' => 'not_configured',
                    'message' => 'API key not configured',
                    'icon' => 'credit-card',
                ];
            }

            // Quick balance check to verify connectivity
            \Stripe\Stripe::setApiKey(config('cashier.secret'));
            $balance = \Stripe\Balance::retrieve();
            
            return [
                'name' => 'Stripe',
                'status' => 'healthy',
                'message' => 'API responding',
                'icon' => 'credit-card',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Stripe',
                'status' => 'error',
                'message' => 'Connection error: ' . substr($e->getMessage(), 0, 50),
                'icon' => 'credit-card',
            ];
        }
    }

    /**
     * Check AWS S3 connectivity.
     */
    private function checkS3(): array
    {
        try {
            $hasCredentials = !empty(config('filesystems.disks.s3.key')) && 
                              !empty(config('filesystems.disks.s3.secret'));
            
            if (!$hasCredentials) {
                return [
                    'name' => 'AWS S3',
                    'status' => 'not_configured',
                    'message' => 'Credentials not configured',
                    'icon' => 'cloud',
                ];
            }

            // Try to list a few objects
            $disk = \Illuminate\Support\Facades\Storage::disk('s3');
            $disk->files('/', 1);
            
            return [
                'name' => 'AWS S3',
                'status' => 'healthy',
                'message' => 'Connected to bucket',
                'icon' => 'cloud',
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'AWS S3',
                'status' => 'error',
                'message' => 'Connection error',
                'icon' => 'cloud',
            ];
        }
    }

    /**
     * Check database health.
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            // Get some stats
            $userCount = DB::table('users')->count();
            $campaignCount = DB::table('campaigns')->count();
            $customerCount = DB::table('customers')->count();
            
            return [
                'status' => 'healthy',
                'latency' => $latency,
                'message' => "Connected ({$latency}ms)",
                'stats' => [
                    'users' => $userCount,
                    'campaigns' => $campaignCount,
                    'customers' => $customerCount,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'latency' => null,
                'message' => 'Database connection failed',
                'stats' => null,
            ];
        }
    }

    /**
     * Get queue statistics.
     */
    private function getQueueStats(): array
    {
        try {
            // Get failed jobs count
            $failedJobs = DB::table('failed_jobs')->count();
            
            // Get pending jobs (if using database queue)
            $pendingJobs = 0;
            if (config('queue.default') === 'database') {
                $pendingJobs = DB::table('jobs')->count();
            }
            
            // Get recent failed jobs
            $recentFailed = DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'queue' => $job->queue,
                        'job' => $payload['displayName'] ?? 'Unknown',
                        'failed_at' => $job->failed_at,
                        'exception' => substr($job->exception, 0, 200),
                    ];
                });

            return [
                'status' => $failedJobs > 10 ? 'warning' : 'healthy',
                'pending' => $pendingJobs,
                'failed' => $failedJobs,
                'recentFailed' => $recentFailed,
                'driver' => config('queue.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'pending' => 0,
                'failed' => 0,
                'recentFailed' => [],
                'driver' => config('queue.default'),
            ];
        }
    }

    /**
     * Check storage health.
     */
    private function checkStorage(): array
    {
        try {
            $totalSpace = disk_total_space(storage_path());
            $freeSpace = disk_free_space(storage_path());
            $usedSpace = $totalSpace - $freeSpace;
            $usedPercent = round(($usedSpace / $totalSpace) * 100, 1);
            
            return [
                'status' => $usedPercent > 90 ? 'warning' : 'healthy',
                'total' => $this->formatBytes($totalSpace),
                'free' => $this->formatBytes($freeSpace),
                'used' => $this->formatBytes($usedSpace),
                'usedPercent' => $usedPercent,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Could not check storage',
            ];
        }
    }

    /**
     * Check cache health.
     */
    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);
            
            return [
                'status' => $value === 'test' ? 'healthy' : 'error',
                'driver' => config('cache.default'),
                'message' => $value === 'test' ? 'Cache working' : 'Cache read/write failed',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'driver' => config('cache.default'),
                'message' => 'Cache error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Format bytes to human readable.
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Retry a failed job.
     */
    public function retryJob(Request $request, $id)
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$id]]);
            
            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Job queued for retry.',
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to retry job: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete a failed job.
     */
    public function deleteJob(Request $request, $id)
    {
        try {
            DB::table('failed_jobs')->where('id', $id)->delete();
            
            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Failed job deleted.',
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to delete job: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Flush all failed jobs.
     */
    public function flushFailedJobs()
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:flush');
            
            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'All failed jobs cleared.',
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to flush jobs: ' . $e->getMessage(),
            ]);
        }
    }
}
