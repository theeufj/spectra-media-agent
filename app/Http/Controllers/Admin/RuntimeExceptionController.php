<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RuntimeException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RuntimeExceptionController extends Controller
{
    public function index(Request $request)
    {
        $query = RuntimeException::query()->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('message', 'ilike', "%{$search}%")
                  ->orWhere('type', 'ilike', "%{$search}%")
                  ->orWhere('file', 'ilike', "%{$search}%")
                  ->orWhere('job_class', 'ilike', "%{$search}%")
                  ->orWhere('url', 'ilike', "%{$search}%");
            });
        }

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $exceptions = $query->paginate(50)->withQueryString();

        // Get summary stats
        $stats = [
            'total' => RuntimeException::count(),
            'today' => RuntimeException::whereDate('created_at', today())->count(),
            'this_week' => RuntimeException::where('created_at', '>=', now()->subWeek())->count(),
            'http' => RuntimeException::where('source', 'http')->whereDate('created_at', today())->count(),
            'queue' => RuntimeException::where('source', 'queue')->whereDate('created_at', today())->count(),
            'console' => RuntimeException::where('source', 'console')->whereDate('created_at', today())->count(),
        ];

        // Get distinct exception types for the filter dropdown
        $types = RuntimeException::distinct()->pluck('type')->sort()->values();

        return Inertia::render('Admin/RuntimeExceptions', [
            'exceptions' => $exceptions,
            'stats' => $stats,
            'types' => $types,
            'filters' => $request->only(['search', 'source', 'type']),
        ]);
    }

    public function show(RuntimeException $runtimeException)
    {
        $runtimeException->load(['user', 'customer']);

        return Inertia::render('Admin/RuntimeExceptionDetail', [
            'exception' => $runtimeException,
        ]);
    }

    public function destroy(RuntimeException $runtimeException)
    {
        $runtimeException->delete();

        return back()->with('success', 'Exception deleted.');
    }

    public function flush(Request $request)
    {
        $days = $request->input('days', 30);
        $deleted = RuntimeException::where('created_at', '<', now()->subDays($days))->delete();

        return back()->with('success', "Cleared {$deleted} exceptions older than {$days} days.");
    }
}
