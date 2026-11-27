<?php

namespace App\Http\Controllers;

use App\Services\SignupFunnel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SignupFunnelStageController
{
    public function __invoke(Request $request, SignupFunnel $funnel): Response
    {
        abort_unless($funnel->ownHost($request), 404);
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || ($request->hasHeader('Sec-Fetch-Site') && $request->header('Sec-Fetch-Site') !== 'same-origin')) {
            abort(403);
        }
        $body = $request->json()->all();
        $stage = $body['stage'] ?? null;
        $reason = $body['reason'] ?? null;
        if (! $request->isJson() || $request->query() !== [] || strlen($request->getContent()) > 512
            || array_diff(array_keys($body), ['stage', 'reason']) || ! is_string($stage) || ! SignupFunnel::validClientStage($stage, $reason)) {
            return response()->json(['message' => 'Invalid funnel stage.'], 422);
        }
        $funnel->clientStage($request, $stage, $reason);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
