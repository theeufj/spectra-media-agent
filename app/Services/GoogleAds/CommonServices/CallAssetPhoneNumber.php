<?php

namespace App\Services\GoogleAds\CommonServices;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** Resolve the phone's numbering region, independently of advertising geography. */
class CallAssetPhoneNumber
{
    /** @return array{phone_number: string, country_code: string}|null */
    public function resolve(string $phone, ?string $nationalCountry = null): ?array
    {
        $phone = trim($phone);
        if (! preg_match('/^\+?[0-9().\s-]+$/D', $phone)) {
            return null;
        }
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        $util = PhoneNumberUtil::getInstance();
        $country = strtoupper(trim($nationalCountry ?? ''));
        if (! str_starts_with($phone, '+') && ! in_array($country, $util->getSupportedRegions(), true)) {
            return null; // A national number has no self-describing country code.
        }
        try {
            $number = $util->parse($phone, str_starts_with($phone, '+') ? null : $country);
            $region = $util->getRegionCodeForNumber($number);
            if (! $util->isValidNumber($number) || ! is_string($region) || ! preg_match('/^[A-Z]{2}$/D', $region)) {
                return null;
            }

            return ['phone_number' => $util->format($number, PhoneNumberFormat::E164), 'country_code' => $region];
        } catch (NumberParseException) {
            return null;
        }
    }
}
