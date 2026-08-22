<?php

namespace App\Http\Controllers;

use App\Services\EmailInboxService;
use App\Services\EmailSequences\SequenceReplyRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;

class ResendInboundWebhookController extends Controller
{
    public function __construct(
        private EmailInboxService $inboxService,
        private SequenceReplyRecorder $replyRecorder,
    ) {}

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
                Log::warning('Resend inbound webhook signature failed: '.$e->getMessage());

                return response('Unauthorized', 401);
            }
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Invalid payload', 400);
        }

        if (($payload['type'] ?? '') === 'email.received') {
            $this->inboxService->processInboundWebhook($payload);

            // A reply to one of the follow-up chains is also an inbound email,
            // so it arrives here. Recorded separately and guarded on its own:
            // a failure capturing a sequence reply must not cost the customer
            // inbox its message, and vice versa.
            try {
                $this->replyRecorder->record($payload['data'] ?? []);
            } catch (\Throwable $e) {
                report($e);
                Log::error('Failed to record a sequence reply', ['error' => $e->getMessage()]);
            }
        }

        return response('OK', 200);
    }
}
