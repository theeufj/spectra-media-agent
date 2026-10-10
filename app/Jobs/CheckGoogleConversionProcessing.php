<?php

namespace App\Jobs;

use App\Models\SpectraConversionEvent;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Accepted uploads can fail later. Check their receipt without uploading again. */
class CheckGoogleConversionProcessing implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 40;

    public int $timeout = 70;

    public int $uniqueFor = 90000;

    public function __construct(public int $eventId, public string $requestId) {}

    public function uniqueId(): string
    {
        return "{$this->eventId}:{$this->requestId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(90)];
    }

    public static function scheduleFor(SpectraConversionEvent $event): void
    {
        if (! self::eligible($event) || self::terminal($event)) {
            return;
        }
        static::dispatch($event->id, $event->google_request_id)
            ->delay($event->google_accepted_at->addMinutes(30))->afterCommit();
    }

    public function handle(DataManagerService $service): void
    {
        $event = SpectraConversionEvent::find($this->eventId);
        if (! $event || ! self::eligible($event) || $event->google_request_id !== $this->requestId || self::terminal($event)) {
            return;
        }
        $deadline = $event->google_accepted_at->addDay();
        if (now()->greaterThanOrEqualTo($deadline)) {
            $this->expire($event);

            return;
        }
        // Respect Google's minimum waiting period even if someone dispatches
        // the job early. Poll delay is fixed to acceptance, not conversion time.
        $firstCheck = $event->google_accepted_at->addMinutes(30);
        if (now()->lessThan($firstCheck)) {
            $this->release((int) ceil(now()->diffInSeconds($firstCheck)));

            return;
        }

        $result = $service->retrieveRequestStatus($this->requestId);
        $details = $result['success']
            ? $this->diagnostics($result['destinations'] ?? [], $event->google_conversion_resource)
            : ['status' => 'CHECK_FAILED', 'message' => $result['error'] ?? 'Google processing status is unavailable.'];
        // A stale/duplicate job cannot overwrite a newer receipt or terminal result.
        $updated = SpectraConversionEvent::whereKey($event->id)->where('google_request_id', $this->requestId)
            ->where(fn ($query) => $query->whereNull('google_processing_status')
                ->orWhereNotIn('google_processing_status', ['SUCCESS', 'FAILED', 'PARTIAL_SUCCESS', 'TIMED_OUT']))
            ->update([
                'google_processing_status' => $details['status'],
                'google_processing_checked_at' => now()->format('Y-m-d H:i:sP'),
                'google_processing_attempts' => $event->google_processing_attempts + 1,
                'google_processing_details' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);
        if (! $updated) {
            return;
        }
        $event->refresh();
        if (in_array($details['status'], ['FAILED', 'PARTIAL_SUCCESS'], true)) {
            report(new \RuntimeException("Google conversion processing failed for event {$event->id}: ".
                implode(', ', array_column($details['errors'] ?? [], 'reason'))));

            return;
        }
        if ($details['status'] === 'SUCCESS') {
            return;
        }
        $delay = min(3600, (int) ceil(1800 * (1.3 ** min($event->google_processing_attempts, 10))));
        $remaining = (int) ceil(now()->diffInSeconds($deadline, false));
        if ($remaining <= 0) {
            $this->expire($event);
        } else {
            $this->release(min($delay, $remaining));
        }
    }

    public function failed(\Throwable $exception): void
    {
        SpectraConversionEvent::whereKey($this->eventId)->where('google_request_id', $this->requestId)
            ->where(fn ($query) => $query->whereNull('google_processing_status')
                ->orWhereNotIn('google_processing_status', ['SUCCESS', 'FAILED', 'PARTIAL_SUCCESS', 'TIMED_OUT']))
            ->update(['google_processing_status' => 'CHECK_FAILED', 'google_processing_checked_at' => now()->format('Y-m-d H:i:sP'),
                'google_processing_details' => json_encode(['message' => 'The processing check could not complete. Check the failed job.'], JSON_THROW_ON_ERROR)]);
    }

    private static function eligible(SpectraConversionEvent $event): bool
    {
        return $event->mode === 'server_google' && in_array($event->event, ['signup', 'paid_subscription'], true)
            && $event->uploaded_to_google && (bool) $event->google_request_id
            && (bool) $event->google_accepted_at && (bool) $event->google_conversion_resource;
    }

    private static function terminal(SpectraConversionEvent $event): bool
    {
        return in_array($event->google_processing_status, ['SUCCESS', 'FAILED', 'PARTIAL_SUCCESS', 'TIMED_OUT'], true);
    }

    private function expire(SpectraConversionEvent $event): void
    {
        $event->update(['google_processing_status' => 'TIMED_OUT', 'google_processing_checked_at' => now(),
            'google_processing_details' => array_merge($event->google_processing_details ?? [],
                ['message' => 'No confirmed processing result within 24 hours. Delivery remains unverified.'])]);
        report(new \RuntimeException("Google conversion processing is unverified after 24 hours for event {$event->id}."));
    }

    /** Only the exact account/action we uploaded to can prove processing. */
    private function diagnostics(array $destinations, string $resource): array
    {
        if (! preg_match('~^customers/(\d+)/conversionActions/(\d+)$~', $resource, $parts) || count($destinations) !== 1) {
            return ['status' => 'CHECK_FAILED', 'message' => 'Processing destinations do not match the saved upload.'];
        }
        $result = reset($destinations);
        if (! is_array($result)
            || data_get($result, 'destination.operatingAccount.accountType') !== 'GOOGLE_ADS'
            || data_get($result, 'destination.operatingAccount.accountId') !== $parts[1]
            || data_get($result, 'destination.productDestinationId') !== $parts[2]) {
            return ['status' => 'CHECK_FAILED', 'message' => 'Processing destination does not match the saved account and action.'];
        }
        $status = $result['requestStatus'] ?? 'REQUEST_STATUS_UNKNOWN';
        $errors = $this->counts(data_get($result, 'errorInfo.errorCounts', []));
        $warnings = $this->counts(data_get($result, 'warningInfo.warningCounts', []));
        $records = data_get($result, 'eventsIngestionStatus.recordCount');
        // We send one real event per request. Record count includes failures;
        // a count alone cannot prove successful processing or ad attribution.
        if ($status === 'SUCCESS' && ((! is_string($records) && ! is_int($records)) || (string) $records !== '1' || $errors !== [])) {
            $status = 'CHECK_FAILED';
        }
        if (! in_array($status, ['SUCCESS', 'PROCESSING', 'FAILED', 'PARTIAL_SUCCESS'], true)) {
            $status = 'CHECK_FAILED';
        }

        return ['status' => $status, 'record_count' => is_numeric($records) ? (int) $records : null,
            'errors' => $errors, 'warnings' => $warnings];
    }

    private function counts(mixed $counts): array
    {
        if (! is_array($counts)) {
            return [];
        }
        $result = [];
        foreach (array_slice($counts, 0, 50) as $count) {
            if (is_array($count) && is_string($count['reason'] ?? null)) {
                $result[] = ['reason' => mb_substr($count['reason'], 0, 200),
                    'record_count' => is_numeric($count['recordCount'] ?? null) ? (int) $count['recordCount'] : null];
            }
        }

        return $result;
    }
}
