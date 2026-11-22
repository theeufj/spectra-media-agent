<?php

namespace App\Services;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Persist the genuine registration before queue dispatch, including organic signups. */
class SiteSignupConversionService
{
    /** Advertising can use only a verified address supplied on our own signup form. */
    public function verifiedFormEmail(User $user, ?SpectraConversionEvent $signup = null): ?string
    {
        if (! $user->hasVerifiedEmail() || $user->ad_measurement_consent !== true || $user->tenant_key !== 'sitetospend') {
            return null;
        }
        $signup ??= SpectraConversionEvent::where('deduplication_key', "signup:{$user->id}:{$user->created_at->timestamp}")->first();

        return $signup?->hasSignupEmailMatch() && hash_equals($signup->verified_source['google_email_hash'], DataManagerService::hashEmail($user->email))
            ? $user->email : null;
    }

    public function emailVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail() || $user->tenant_key !== 'sitetospend' || $user->ad_measurement_consent !== true) {
            return;
        }
        $pending = DB::transaction(function () use ($user) {
            $event = SpectraConversionEvent::where('deduplication_key', "signup:{$user->id}:{$user->created_at->timestamp}")->lockForUpdate()->first();
            if (! $event || ! $event->hasSignupEmailMatch() || ! hash_equals($event->verified_source['google_email_hash'], DataManagerService::hashEmail($user->email))
                || ! empty($event->verified_source['email_verified_at'])) {
                return null;
            }
            $event->update(['verified_source' => [...$event->verified_source, 'email_verified_at' => $user->fromDateTime($user->email_verified_at)]]);

            return ! $event->uploaded_to_google && ! $event->hasGoogleClickIdentifier() ? $event : null;
        });
        if ($pending) {
            $this->dispatch($user, $pending);
        }
    }

    /** Recover durable registrations if the process or the queue connection stopped at dispatch. */
    public function retryPendingDispatches(): void
    {
        SpectraConversionEvent::where('event', 'signup')->where('mode', 'server_google')->where('uploaded_to_google', false)
            ->where('verified_source->kind', 'registration')->where('verified_source->consent', true)
            ->whereHas('user', fn ($users) => $users->where('tenant_key', 'sitetospend')->where('ad_measurement_consent', true))
            ->whereBetween('created_at', [now()->subDays(7), now()->subHour()])
            ->where(fn ($rows) => $rows->whereNull('upload_error')->orWhere('upload_error', 'like', 'Queue dispatch failed:%'))
            ->with('user')->eachById(function (SpectraConversionEvent $event) {
                if ($event->user && ($event->hasGoogleClickIdentifier() || ($event->hasSignupEmailMatch() && $event->user->hasVerifiedEmail()))) {
                    RecordSiteGoogleConversion::dispatch($event->user, 'signup', $event->occurred_at, $event->id);
                }
            }, 100);
    }

    public function record(User $user, ?string $disclosureVersion = null, bool $formSuppliedEmail = false): SpectraConversionEvent
    {
        $occurredAt = $user->created_at;
        if (! $user->exists || ! $occurredAt) {
            throw new \LogicException('A signup conversion requires a persisted registration.');
        }
        $identifiers = $user->googleAdIdentifiers();
        $source = ['kind' => 'registration', 'user_id' => $user->id, 'consent' => $user->ad_measurement_consent,
            'tenant' => $user->tenant_key, 'disclosure_version' => $disclosureVersion, 'form_supplied_email' => $formSuppliedEmail];
        if ($formSuppliedEmail && $user->tenant_key === 'sitetospend' && $user->ad_measurement_consent === true && $disclosureVersion === 'signup-email-v1') {
            $source['google_email_hash'] = DataManagerService::hashEmail($user->email);
            $source['email_verified_at'] = $user->fromDateTime($user->email_verified_at);
        }
        $config = config('conversions.events.signup', []);
        $event = DB::transaction(fn () => SpectraConversionEvent::firstOrCreate([
            'deduplication_key' => "signup:{$user->id}:{$occurredAt->timestamp}",
        ], [
            'verified_source' => $source,
            'event' => 'signup', 'user_id' => $user->id, 'mode' => 'server_google',
            'value' => $config['value'] ?? 0, 'currency' => $config['currency'] ?? 'USD',
            'occurred_at' => $occurredAt, 'ad_identifiers' => $identifiers,
            'gclid' => $user->gclid, 'google_conversion_resource' => \App\Models\Setting::get('conversion_resource_name.signup_import'), 'uploaded_to_google' => false,
        ]));

        if (($event->wasRecentlyCreated || $event->upload_error) && ! $event->uploaded_to_google
            && $user->tenant_key === 'sitetospend' && $user->ad_measurement_consent === true && ($event->verified_source['consent'] ?? null) === true
            && ($event->ad_identifiers || ($event->hasSignupEmailMatch() && $user->hasVerifiedEmail()))) {
            $this->dispatch($user, $event);
        }

        return $event;
    }

    private function dispatch(User $user, SpectraConversionEvent $event): void
    {
        try {
            Bus::dispatch((new RecordSiteGoogleConversion($user, 'signup', $event->occurred_at, $event->id))->afterCommit());
        } catch (\Throwable $e) {
            $event->update(['upload_error' => 'Queue dispatch failed: pending retry.']);
            report($e);
            Log::error('Signup conversion could not be queued', ['event_id' => $event->id]);
        }
    }
}
