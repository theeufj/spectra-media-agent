<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class Json
{
    public static function safeDecode(mixed $json, bool $assoc = true): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        $data = json_decode($json, $assoc);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Json::safeDecode error: ' . json_last_error_msg(), [
                'preview' => substr($json, 0, 200),
            ]);
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
