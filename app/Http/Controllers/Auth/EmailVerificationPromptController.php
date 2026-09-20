<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|Response|JsonResponse
    {
        $verified = $request->user()->hasVerifiedEmail();

        if ($request->expectsJson()) {
            return response()->json(['verified' => $verified])->header('Cache-Control', 'no-store');
        }

        // Do not send an already-verified user back to an intended verification
        // page. Dashboard routes new accounts through Quick Start as needed.
        return $verified
                    ? redirect()->route('dashboard')
                    : Inertia::render('Auth/VerifyEmail', ['status' => session('status')]);
    }
}
