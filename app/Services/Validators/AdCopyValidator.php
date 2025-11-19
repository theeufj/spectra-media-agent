<?php

namespace App\Services\Validators;

use Illuminate\Support\Str;

class AdCopyValidator
{
    /**
     * Character limits for different platforms and ad types.
     */
    private const LIMITS = [
        'google_rsa' => [
            'headline' => 30,
            'description' => 90,
            'min_headlines' => 3,
            'max_headlines' => 15,
            'min_descriptions' => 2,
            'max_descriptions' => 4,
        ],
        'google_display' => [
            'headline' => 30,
            'description' => 90,
            'long_headline' => 90,
        ],
        'facebook' => [
            'headline' => 40, // Primary text can be longer
            'description' => 30, // Link description
            'body' => 125, // Ad body text
        ],
    ];

    /**
     * Validate Google Responsive Search Ad copy.
     *
     * @param array $headlines Array of headline strings
     * @param array $descriptions Array of description strings
     * @return array Validation errors (empty if valid)
     */
    public function validateGoogleRSA(array $headlines, array $descriptions): array
    {
        $errors = [];
        $limits = self::LIMITS['google_rsa'];

        // Check headline count
        if (count($headlines) < $limits['min_headlines']) {
            $errors[] = "Google RSA requires at least {$limits['min_headlines']} headlines (provided: " . count($headlines) . ")";
        }

        if (count($headlines) > $limits['max_headlines']) {
            $errors[] = "Google RSA allows maximum {$limits['max_headlines']} headlines (provided: " . count($headlines) . ")";
        }

        // Check description count
        if (count($descriptions) < $limits['min_descriptions']) {
            $errors[] = "Google RSA requires at least {$limits['min_descriptions']} descriptions (provided: " . count($descriptions) . ")";
        }

        if (count($descriptions) > $limits['max_descriptions']) {
            $errors[] = "Google RSA allows maximum {$limits['max_descriptions']} descriptions (provided: " . count($descriptions) . ")";
        }

        // Validate headline lengths
        foreach ($headlines as $index => $headline) {
            $length = mb_strlen($headline);
            if ($length > $limits['headline']) {
                $errors[] = "Headline " . ($index + 1) . " exceeds {$limits['headline']} characters (length: {$length})";
            }
            if (empty(trim($headline))) {
                $errors[] = "Headline " . ($index + 1) . " cannot be empty";
            }
        }

        // Validate description lengths
        foreach ($descriptions as $index => $description) {
            $length = mb_strlen($description);
            if ($length > $limits['description']) {
                $errors[] = "Description " . ($index + 1) . " exceeds {$limits['description']} characters (length: {$length})";
            }
            if (empty(trim($description))) {
                $errors[] = "Description " . ($index + 1) . " cannot be empty";
            }
        }

        return $errors;
    }

    /**
     * Validate Google Display Ad copy.
     *
     * @param string $headline Short headline
     * @param string|null $longHeadline Long headline (optional)
     * @param string $description Description text
     * @return array Validation errors (empty if valid)
     */
    public function validateGoogleDisplay(
        string $headline,
        ?string $longHeadline,
        string $description
    ): array {
        $errors = [];
        $limits = self::LIMITS['google_display'];

        // Validate short headline
        $headlineLength = mb_strlen($headline);
        if ($headlineLength > $limits['headline']) {
            $errors[] = "Headline exceeds {$limits['headline']} characters (length: {$headlineLength})";
        }
        if (empty(trim($headline))) {
            $errors[] = "Headline cannot be empty";
        }

        // Validate long headline if provided
        if ($longHeadline !== null) {
            $longHeadlineLength = mb_strlen($longHeadline);
            if ($longHeadlineLength > $limits['long_headline']) {
                $errors[] = "Long headline exceeds {$limits['long_headline']} characters (length: {$longHeadlineLength})";
            }
        }

        // Validate description
        $descriptionLength = mb_strlen($description);
        if ($descriptionLength > $limits['description']) {
            $errors[] = "Description exceeds {$limits['description']} characters (length: {$descriptionLength})";
        }
        if (empty(trim($description))) {
            $errors[] = "Description cannot be empty";
        }

        return $errors;
    }

    /**
     * Validate Facebook Ad copy.
     *
     * @param string $headline Primary text/headline
     * @param string $body Ad body text
     * @param string|null $description Link description (optional)
     * @return array Validation errors (empty if valid)
     */
    public function validateFacebook(
        string $headline,
        string $body,
        ?string $description = null
    ): array {
        $errors = [];
        $limits = self::LIMITS['facebook'];

        // Validate headline
        $headlineLength = mb_strlen($headline);
        if ($headlineLength > $limits['headline']) {
            $errors[] = "Headline exceeds {$limits['headline']} characters (length: {$headlineLength})";
        }
        if (empty(trim($headline))) {
            $errors[] = "Headline cannot be empty";
        }

        // Validate body
        $bodyLength = mb_strlen($body);
        if ($bodyLength > $limits['body']) {
            $errors[] = "Body text exceeds {$limits['body']} characters (length: {$bodyLength})";
        }
        if (empty(trim($body))) {
            $errors[] = "Body text cannot be empty";
        }

        // Validate description if provided
        if ($description !== null) {
            $descriptionLength = mb_strlen($description);
            if ($descriptionLength > $limits['description']) {
                $errors[] = "Link description exceeds {$limits['description']} characters (length: {$descriptionLength})";
            }
        }

        return $errors;
    }

    /**
     * Auto-truncate text to fit character limit while preserving words.
     *
     * @param string $text Text to truncate
     * @param int $limit Character limit
     * @param string $suffix Suffix to append (default: "...")
     * @return string Truncated text
     */
    public function truncate(string $text, int $limit, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Account for suffix length
        $maxLength = $limit - mb_strlen($suffix);
        
        // Truncate at word boundary
        $truncated = Str::substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');
        
        if ($lastSpace !== false) {
            $truncated = Str::substr($truncated, 0, $lastSpace);
        }

        return $truncated . $suffix;
    }

    /**
     * Get character limits for a specific platform and field.
     *
     * @param string $platform 'google_rsa', 'google_display', or 'facebook'
     * @param string $field Field name (e.g., 'headline', 'description')
     * @return int|null Character limit or null if not found
     */
    public function getLimit(string $platform, string $field): ?int
    {
        return self::LIMITS[$platform][$field] ?? null;
    }

    /**
     * Get all limits for a platform.
     *
     * @param string $platform 'google_rsa', 'google_display', or 'facebook'
     * @return array|null Limits array or null if platform not found
     */
    public function getLimits(string $platform): ?array
    {
        return self::LIMITS[$platform] ?? null;
    }
}
