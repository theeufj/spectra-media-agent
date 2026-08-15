<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExceptionLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RuntimeExceptionController extends Controller
{
    public function index(Request $request)
    {
        $query = ExceptionLog::query()->latest();

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
            'total' => ExceptionLog::count(),
            'today' => ExceptionLog::whereDate('created_at', today())->count(),
            'this_week' => ExceptionLog::where('created_at', '>=', now()->subWeek())->count(),
            'http' => ExceptionLog::where('source', 'http')->whereDate('created_at', today())->count(),
            'queue' => ExceptionLog::where('source', 'queue')->whereDate('created_at', today())->count(),
            'console' => ExceptionLog::where('source', 'console')->whereDate('created_at', today())->count(),
        ];

        // Get distinct exception types for the filter dropdown
        $types = ExceptionLog::distinct()->pluck('type')->sort()->values();

        return Inertia::render('Admin/RuntimeExceptions', [
            'exceptions' => $exceptions,
            'stats' => $stats,
            'types' => $types,
            'filters' => $request->only(['search', 'source', 'type']),
        ]);
    }

    // Typed ExceptionLog, not RuntimeException. The route parameter is named
    // {runtimeException}, and an unqualified `RuntimeException` type-hint here
    // resolved to App\Http\Controllers\Admin\RuntimeException — a class that
    // does not exist — so implicit binding fatalled and the exception detail
    // page could never open. Binding still works: it keys off the parameter
    // name, not the class name.
    public function show(ExceptionLog $runtimeException)
    {
        $runtimeException->load(['user', 'customer']);

        return Inertia::render('Admin/RuntimeExceptionDetail', [
            'exception' => $runtimeException,
        ]);
    }

    public function destroy(ExceptionLog $runtimeException)
    {
        $runtimeException->delete();

        return back()->with('success', 'Exception deleted.');
    }

    public function flush(Request $request)
    {
        $days = $request->input('days', 30);
        $deleted = ExceptionLog::where('created_at', '<', now()->subDays($days))->delete();

        return back()->with('success', "Cleared {$deleted} exceptions older than {$days} days.");
    }
}
