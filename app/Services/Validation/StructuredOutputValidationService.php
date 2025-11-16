<?php

namespace App\Services\Validation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StructuredOutputValidationService
{
    public function __invoke(string $json, array $rules): ?array
    {
        try {
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Structured output validation failed: Invalid JSON.");
                return null;
            }

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                Log::error("Structured output validation failed: " . $validator->errors()->first());
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("Error during structured output validation: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }
}
