<?php

namespace App\Services;

use App\Models\EmailAttachment;
use App\Models\EmailInbox;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Resend\Laravel\Facades\Resend;

class EmailInboxService
{
    public function processInboundWebhook(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $resendEmailId = $data['email_id'] ?? null;

        if (! $resendEmailId) {
            Log::warning('Resend inbound webhook missing email_id', $payload);
            return;
        }

        $toAddresses = $data['to'] ?? [];
        $inbox = $this->resolveInbox($toAddresses);

        if (! $inbox) {
            Log::info('No inbox found for inbound addresses', ['to' => $toAddresses]);
            return;
        }

        try {
            $email = Resend::emails()->receiving->get($resendEmailId);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch received email from Resend', [
                'email_id' => $resendEmailId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $inReplyTo = $this->extractHeader($email->headers ?? [], 'in-reply-to');
        $threadId = $this->resolveThreadId($inbox->id, $inReplyTo);

        $message = EmailMessage::create([
            'inbox_id' => $inbox->id,
            'resend_email_id' => $resendEmailId,
            'direction' => 'inbound',
            'from_address' => $email->from ?? ($data['from'] ?? ''),
            'to_addresses' => $email->to ?? $toAddresses,
            'cc_addresses' => $email->cc ?? [],
            'bcc_addresses' => $email->bcc ?? [],
            'subject' => $email->subject ?? ($data['subject'] ?? '(no subject)'),
            'html_body' => $email->html ?? null,
            'text_body' => $email->text ?? null,
            'message_id' => $email->message_id ?? ($data['message_id'] ?? null),
            'thread_id' => $threadId,
            'in_reply_to' => $inReplyTo,
            'sent_at' => now(),
        ]);

        foreach ($email->attachments ?? [] as $att) {
            $this->storeAttachment($message, $resendEmailId, $att);
        }
    }

    public function sendEmail(EmailInbox $inbox, array $params, array $uploadedFiles = []): EmailMessage
    {
        $attachments = [];

        foreach ($uploadedFiles as $file) {
            $attachments[] = [
                'filename' => $file->getClientOriginalName(),
                'content' => base64_encode(file_get_contents($file->getRealPath())),
            ];
        }

        $payload = array_filter([
            'from' => "{$inbox->display_name} <{$inbox->email_address}>",
            'to' => (array) $params['to'],
            'cc' => ! empty($params['cc']) ? (array) $params['cc'] : null,
            'bcc' => ! empty($params['bcc']) ? (array) $params['bcc'] : null,
            'subject' => $params['subject'],
            'html' => $params['html'] ?? null,
            'text' => $params['text'] ?? null,
            'reply_to' => ! empty($params['reply_to']) ? $params['reply_to'] : null,
            'headers' => ! empty($params['in_reply_to']) ? [
                'In-Reply-To' => $params['in_reply_to'],
                'References' => $params['references'] ?? $params['in_reply_to'],
            ] : null,
            'attachments' => ! empty($attachments) ? $attachments : null,
        ]);

        $sent = Resend::emails()->send($payload);

        $threadId = ! empty($params['thread_id'])
            ? $params['thread_id']
            : Str::uuid()->toString();

        $message = EmailMessage::create([
            'inbox_id' => $inbox->id,
            'resend_email_id' => $sent->id ?? null,
            'direction' => 'outbound',
            'from_address' => $inbox->email_address,
            'to_addresses' => (array) $params['to'],
            'cc_addresses' => ! empty($params['cc']) ? (array) $params['cc'] : [],
            'bcc_addresses' => ! empty($params['bcc']) ? (array) $params['bcc'] : [],
            'subject' => $params['subject'],
            'html_body' => $params['html'] ?? null,
            'text_body' => $params['text'] ?? null,
            'message_id' => null,
            'thread_id' => $threadId,
            'in_reply_to' => $params['in_reply_to'] ?? null,
            'read_at' => now(),
            'sent_at' => now(),
        ]);

        foreach ($uploadedFiles as $file) {
            $path = $file->store("email-attachments/{$message->id}", 's3');

            EmailAttachment::create([
                'email_message_id' => $message->id,
                'filename' => $file->getClientOriginalName(),
                'content_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'storage_disk' => 's3',
                'storage_path' => $path,
            ]);
        }

        return $message;
    }

    private function resolveInbox(array $toAddresses): ?EmailInbox
    {
        foreach ($toAddresses as $address) {
            $address = strtolower(trim($address));
            $inbox = EmailInbox::whereRaw('LOWER(email_address) = ?', [$address])->first();
            if ($inbox) {
                return $inbox;
            }
        }

        return null;
    }

    private function resolveThreadId(int $inboxId, ?string $inReplyTo): string
    {
        if ($inReplyTo) {
            $parent = EmailMessage::where('inbox_id', $inboxId)
                ->where('message_id', $inReplyTo)
                ->first();

            if ($parent) {
                return $parent->thread_id;
            }
        }

        return Str::uuid()->toString();
    }

    private function extractHeader(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $header) {
            if (strtolower($header['name'] ?? '') === $name) {
                return $header['value'] ?? null;
            }
        }

        return null;
    }

    private function storeAttachment(EmailMessage $message, string $resendEmailId, array $att): void
    {
        $attachmentId = $att['id'] ?? null;
        $filename = $att['filename'] ?? 'attachment';
        $contentType = $att['content_type'] ?? 'application/octet-stream';
        $size = $att['size'] ?? 0;

        $storagePath = null;

        if ($attachmentId) {
            try {
                $meta = Resend::emails()->receiving->attachments->get($resendEmailId, $attachmentId);
                $downloadUrl = $meta->download_url ?? null;

                if ($downloadUrl) {
                    $response = Http::timeout(60)->get($downloadUrl);
                    if ($response->successful()) {
                        $storagePath = "email-attachments/{$message->id}/{$filename}";
                        Storage::disk('s3')->put($storagePath, $response->body());
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to download inbound email attachment', [
                    'attachment_id' => $attachmentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        EmailAttachment::create([
            'email_message_id' => $message->id,
            'resend_attachment_id' => $attachmentId,
            'filename' => $filename,
            'content_type' => $contentType,
            'size' => $size,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
        ]);
    }
}
