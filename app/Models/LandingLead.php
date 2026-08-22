<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who asked the landing page to look at their website.
 *
 * They typed a URL and an email to get a result back, so they are a contact
 * who asked to hear from us — not a scraped address. Until this existed
 * Api\DemoController emailed the team and threw the lead away, so every one of
 * them was lost the moment the page closed.
 */
class LandingLead extends Model
{
    protected $fillable = ['email', 'first_name', 'url', 'converted_user_id', 'converted_at', 'unsubscribed_at'];

    protected $casts = [
        'converted_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    /**
     * Still worth writing to?
     *
     * A lead who has since registered is handed over to the signed-up
     * sequence; continuing to tell them what they are missing after they have
     * joined is the fastest way to look like nobody is paying attention.
     */
    public function isContactable(): bool
    {
        return $this->unsubscribed_at === null && $this->converted_at === null;
    }

    public function scopeContactable($query)
    {
        return $query->whereNull('unsubscribed_at')->whereNull('converted_at');
    }
}
