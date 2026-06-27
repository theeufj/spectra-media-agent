<?php

namespace App\Http\Controllers;

use App\Services\EmailInboxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Resend\WebhookSignature;
use Resend\Exceptions\WebhookSignatureVerificationException;

class ResendInboundWebhookController extends Controller
{
    public function __construct(private EmailInboxService $inboxService) {}

    public function handle(Request $request): Response
    {
        $secret = config('resend.webhook.secret');

        if ($secret) {
            try {
                $headers = [];
                foreach ($request->headers->all() as $key => $value) {
                    $headers[$key] = $value[0];
                }

                WebhookSignature::verify(
                    $request->getContent(),
                    $headers,
                    $secret
                );
            } catch (WebhookSignatureVerificationException $e) {
                Log::warning('Resend inbound webhook signature failed: ' . $e->getMessage());
                return response('Unauthorized', 401);
            }
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Invalid payload', 400);
        }

        if (($payload['type'] ?? '') === 'email.received') {
            $this->inboxService->processInboundWebhook($payload);
        }

        return response('OK', 200);
    }
}
