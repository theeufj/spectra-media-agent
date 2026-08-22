<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A follow-up chain, edited in the admin portal.
 *
 * Audience decides who enters it; the steps decide what they receive and when.
 * Disabled by default — a sequence that starts writing to real people the
 * moment it is created is not a feature anyone wants to discover.
 */
class EmailSequence extends Model
{
    /** Someone gave the landing page their website and never registered. */
    public const AUDIENCE_LANDING_LEAD = 'landing_lead';

    /** Someone registered and never created an account. */
    public const AUDIENCE_DORMANT_SIGNUP = 'dormant_signup';

    protected $fillable = [
        'key', 'label', 'description', 'audience',
        'from_email', 'from_name', 'reply_to', 'signature', 'enabled',
    ];

    protected $casts = ['enabled' => 'boolean'];

    /** @return HasMany<EmailSequenceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(EmailSequenceStep::class)->orderBy('position');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
