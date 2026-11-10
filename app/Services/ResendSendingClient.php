<?php

namespace App\Services;

use Resend;
use Resend\Client;

class ResendSendingClient
{
    public function forSender(string $address): ?Client
    {
        $key = config('services.resend.real_property_ads_key');

        if (! is_string($key) || trim($key) === '' || ! str_ends_with(strtolower($address), '@realpropertyads.com')) {
            return null;
        }

        return Resend::client($key);
    }
}
