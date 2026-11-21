<?php

namespace App\Services\Conversions;

use Illuminate\Support\Facades\Crypt;

class OutcomeReceiptAudit
{
    /** The actual browser/provider deduplication identifier can itself contain identifying data. */
    public static function providerIdentity(string $eventId): array
    {
        return ['provider_event_id' => Crypt::encryptString($eventId), 'provider_event_id_hash' => self::digest($eventId)];
    }

    public static function retryHash(array $validatedPayload): string
    {
        ksort($validatedPayload);

        return self::digest(json_encode($validatedPayload, JSON_THROW_ON_ERROR));
    }

    private static function digest(string $value): string
    {
        return hash_hmac('sha256', $value, app('encrypter')->getKey());
    }
}
