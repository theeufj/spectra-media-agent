<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributionTouchpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'visitor_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'page_url',
        'referrer',
        'touched_at',
    ];

    protected $casts = [
        'touched_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForVisitor($query, string $visitorId)
    {
        return $query->where('visitor_id', $visitorId)->orderBy('touched_at');
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Get the channel label from UTM parameters.
     */
    public function getChannelAttribute(): string
    {
        if ($this->utm_source && $this->utm_medium) {
            return ucfirst($this->utm_source) . ' / ' . ucfirst($this->utm_medium);
        }
        return $this->utm_source ? ucfirst($this->utm_source) : 'Direct';
    }
}
