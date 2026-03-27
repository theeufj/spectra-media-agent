<?php

namespace App\Http\Controllers;

use App\Models\AuditSession;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    /**
     * Show the free audit landing page.
     */
    public function index(Request $request)
    {
        return Inertia::render('FreeAudit', [
            'error' => session('error'),
        ]);
    }

    /**
     * Show the audit results page.
     */
    public function show(string $token)
    {
        $auditSession = AuditSession::where('token', $token)->firstOrFail();

        return Inertia::render('FreeAuditResults', [
            'audit' => [
                'token' => $auditSession->token,
                'platform' => $auditSession->platform,
                'status' => $auditSession->status,
                'score' => $auditSession->score,
                'results' => $auditSession->audit_results,
                'completedAt' => $auditSession->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * API endpoint to poll audit status (for real-time updates).
     */
    public function status(string $token)
    {
        $auditSession = AuditSession::where('token', $token)->firstOrFail();

        return response()->json([
            'status' => $auditSession->status,
            'score' => $auditSession->score,
            'results' => $auditSession->status === 'completed' ? $auditSession->audit_results : null,
            'completedAt' => $auditSession->completed_at?->toIso8601String(),
        ]);
    }
}
