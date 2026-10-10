<?php

namespace App\Services;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Persist the genuine registration before queue dispatch, including organic signups. */
class SiteSignupConversionService
{
    public function record(User $user): SpectraConversionEvent
    {
        $occurredAt = $user->created_at;
        if (! $user->exists || ! $occurredAt) {
            throw new \LogicException('A signup conversion requires a persisted registration.');
        }
        $identifiers = $user->googleAdIdentifiers();
        $config = config('conversions.events.signup', []);
        $event = DB::transaction(fn () => SpectraConversionEvent::firstOrCreate([
            'deduplication_key' => "signup:{$user->id}:{$occurredAt->timestamp}",
        ], [
            'event' => 'signup', 'user_id' => $user->id, 'mode' => 'server_google',
            'value' => $config['value'] ?? 0, 'currency' => $config['currency'] ?? 'USD',
            'occurred_at' => $occurredAt, 'ad_identifiers' => $identifiers,
            'gclid' => $user->gclid, 'uploaded_to_google' => false,
        ]));

        if (($event->wasRecentlyCreated || $event->upload_error) && $event->ad_identifiers && ! $event->uploaded_to_google) {
            try {
                Bus::dispatch((new RecordSiteGoogleConversion($user, 'signup', $event->occurred_at, $event->id))->afterCommit());
            } catch (\Throwable $e) {
                // Registration succeeds even if the queue is down; the durable
                // event explains the missing delivery and can be retried safely.
                $event->update(['upload_error' => 'Queue dispatch failed: '.mb_substr($e->getMessage(), 0, 1900)]);
                report($e);
                Log::error('Signup conversion could not be queued', ['event_id' => $event->id]);
            }
        }

        return $event;
    }
}
