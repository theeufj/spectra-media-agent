<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_id',
        'geo_locations',
        'excluded_geo_locations',
        'age_min',
        'age_max',
        'genders',
        'languages',
        'custom_audiences',
        'lookalike_audiences',
        'interests',
        'behaviors',
        'device_types',
        'placements',
        'excluded_placements',
        'platform',
        'google_options',
        'facebook_options',
    ];

    protected $casts = [
        'geo_locations' => 'array',
        'excluded_geo_locations' => 'array',
        'age_min' => 'integer',
        'age_max' => 'integer',
        'genders' => 'array',
        'languages' => 'array',
        'custom_audiences' => 'array',
        'lookalike_audiences' => 'array',
        'interests' => 'array',
        'behaviors' => 'array',
        'device_types' => 'array',
        'placements' => 'array',
        'excluded_placements' => 'array',
        'google_options' => 'array',
        'facebook_options' => 'array',
    ];

    /**
     * Get the strategy that owns this targeting config.
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * Get geo locations formatted for Google Ads.
     *
     * @return array Array of location criterion IDs
     */
    public function getGoogleGeoTargeting(): array
    {
        if (!$this->geo_locations) {
            return [2840]; // Default: United States
        }

        // Convert location objects to Google Ads geo target constants
        // This would need a mapping service in production
        return array_map(function ($location) {
            return $location['google_criterion_id'] ?? null;
        }, $this->geo_locations);
    }

    /**
     * Get geo locations formatted for Facebook Ads.
     *
     * @return array Array of location targeting objects
     */
    public function getFacebookGeoTargeting(): array
    {
        if (!$this->geo_locations) {
            return [['key' => 'US']]; // Default: United States
        }

        return array_map(function ($location) {
            $targeting = [];
            
            if (isset($location['country'])) {
                $targeting['countries'] = [$location['country']];
            }
            
            if (isset($location['region'])) {
                $targeting['regions'] = [['key' => $location['region']]];
            }
            
            if (isset($location['city'])) {
                $targeting['cities'] = [['key' => $location['city']]];
            }
            
            return $targeting;
        }, $this->geo_locations);
    }

    /**
     * Get age range formatted for Google Ads.
     *
     * @return array Array of age range constants
     */
    public function getGoogleAgeTargeting(): array
    {
        // Map age ranges to Google Ads age range criterion IDs
        // Reference: https://developers.google.com/google-ads/api/reference/rpc/latest/AgeRangeTypeEnum.AgeRangeType
        
        $ageRanges = [];
        
        if ($this->age_min <= 24 && $this->age_max >= 18) {
            $ageRanges[] = 503001; // AGE_RANGE_18_24
        }
        if ($this->age_min <= 34 && $this->age_max >= 25) {
            $ageRanges[] = 503002; // AGE_RANGE_25_34
        }
        if ($this->age_min <= 44 && $this->age_max >= 35) {
            $ageRanges[] = 503003; // AGE_RANGE_35_44
        }
        if ($this->age_min <= 54 && $this->age_max >= 45) {
            $ageRanges[] = 503004; // AGE_RANGE_45_54
        }
        if ($this->age_min <= 64 && $this->age_max >= 55) {
            $ageRanges[] = 503005; // AGE_RANGE_55_64
        }
        if ($this->age_max >= 65) {
            $ageRanges[] = 503006; // AGE_RANGE_65_UP
        }
        
        return $ageRanges;
    }

    /**
     * Get age range formatted for Facebook Ads.
     *
     * @return array Age targeting array
     */
    public function getFacebookAgeTargeting(): array
    {
        return [
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
        ];
    }

    /**
     * Get gender targeting formatted for Google Ads.
     *
     * @return array Array of gender criterion IDs
     */
    public function getGoogleGenderTargeting(): array
    {
        if (!$this->genders || in_array('all', $this->genders)) {
            return [10, 11]; // Male and Female
        }

        $genderMap = [
            'male' => 10,
            'female' => 11,
        ];

        return array_map(fn($gender) => $genderMap[$gender] ?? null, $this->genders);
    }

    /**
     * Get gender targeting formatted for Facebook Ads.
     *
     * @return array Array of gender values (1=male, 2=female)
     */
    public function getFacebookGenderTargeting(): array
    {
        if (!$this->genders || in_array('all', $this->genders)) {
            return [1, 2]; // Male and Female
        }

        $genderMap = [
            'male' => 1,
            'female' => 2,
        ];

        return array_map(fn($gender) => $genderMap[$gender] ?? null, $this->genders);
    }

    /**
     * Check if targeting is compatible with a specific platform.
     *
     * @param string $platform 'google' or 'facebook'
     * @return bool
     */
    public function isCompatibleWith(string $platform): bool
    {
        return $this->platform === 'both' || $this->platform === $platform;
    }

    /**
     * Get default targeting config for a platform.
     *
     * @param string $platform 'google' or 'facebook'
     * @return array
     */
    public static function getDefaultConfig(string $platform = 'both'): array
    {
        return [
            'geo_locations' => [['country' => 'US', 'google_criterion_id' => 2840]],
            'age_min' => 18,
            'age_max' => 65,
            'genders' => ['all'],
            'device_types' => ['desktop', 'mobile', 'tablet'],
            'platform' => $platform,
        ];
    }
}
