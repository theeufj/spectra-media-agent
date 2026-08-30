<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound email, recorded at the moment the transport accepted it.
 * Written by App\Listeners\LogSentEmail; surfaced on the admin customer
 * profile as a per-customer send history.
 */
class EmailLog extends Model
{
    use BelongsToCustomer;

    /**
     * Internal headers stamped onto a message at build time so the listener
     * can file it under the right customer. They exist only between build
     * and transport: LogSentEmail strips them on MessageSending, so they are
     * never delivered to the recipient.
     */
    public const HEADER_MAILABLE = 'X-App-Mailable';

    public const HEADER_CUSTOMER = 'X-Customer-Id';

    protected $fillable = [
        'customer_id',
        'to_email',
        'subject',
        'mailable',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The headers a mailable or notification should stamp: its class name
     * and the customer it is about. Single definition of the contract shared
     * by AppMailable, TenantAware and LogSentEmail. The caller resolves the
     * customer itself (Tenant::customerFromModels over its own properties) —
     * resolution can't live here because get_object_vars() only sees public
     * properties from foreign scope, and notifications hold their models
     * protected.
     *
     * @return array<string, string>
     */
    public static function stampHeaders(object $source, ?Customer $customer): array
    {
        return array_filter([
            self::HEADER_MAILABLE => $source::class,
            self::HEADER_CUSTOMER => $customer?->id ? (string) $customer->id : null,
        ]);
    }
}
