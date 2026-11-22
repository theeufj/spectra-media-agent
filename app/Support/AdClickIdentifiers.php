<?php

namespace App\Support;

class AdClickIdentifiers
{
    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._~+\/=\-]{1,255}\z/D', $value) === 1;
    }

    /** Google's web attribution precedence when a landing URL carries both braids. */
    public static function google(array $values): ?array
    {
        foreach (['gclid', 'wbraid', 'gbraid'] as $type) {
            if (self::valid($values[$type] ?? null)) {
                return [$type => $values[$type]];
            }
        }

        return null;
    }
}
