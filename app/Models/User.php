<?php

namespace App\Models;

use App\Mail\InvoiceCreated;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Billable;

/**
 * Larastan types model attributes from the column, so a json column reads as
 * string|null however it is cast. notification_preferences IS cast to array —
 * see casts() — and every caller treats it as one, so the annotation states
 * the truth rather than every usage site working around a wrong inference.
 *
 * @property array<string, mixed>|null $notification_preferences
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasFactory, Notifiable;

    /**
     * Tenant-aware replacement for the stock verification email: branded for
     * the skin the user signed up under, linking to that skin's own domain
     * (where their session actually lives).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailAddress);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'customer_id',
        'notification_preferences',
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclid',
        'demo_url',
        'tenant_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            // Encrypted at rest: a database dump should not hand over the
            // second factor along with the first.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the third-party connections for the user.
     */
    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function knowledgeBases()
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    /**
     * The customers that the user belongs to.
     */
    /**
     * The Google Ads click identifier this user arrived with, if any.
     *
     * Google sends exactly one of these per click: gclid normally, or wbraid /
     * gbraid where iOS ATT prevents it. They are mutually exclusive, so the
     * first one present is the one to use — and code that checks only gclid
     * silently drops every iOS visitor.
     *
     * Shaped for the Data Manager API's adIdentifiers field.
     *
     * @return array{gclid: string}|array{gbraid: string}|array{wbraid: string}|null
     */
    public function googleAdIdentifiers(): ?array
    {
        foreach (['gclid', 'gbraid', 'wbraid'] as $type) {
            if (! empty($this->{$type})) {
                return [$type => $this->{$type}];
            }
        }

        return null;
    }

    /**
     * Did this user arrive from a Google Ad by any identifier?
     */
    public function hasGoogleClickId(): bool
    {
        return $this->googleAdIdentifiers() !== null;
    }

    /**
     * The raw value of that identifier, whichever type it is.
     */
    public function googleClickId(): ?string
    {
        $identifiers = $this->googleAdIdentifiers();

        return $identifiers ? reset($identifiers) : null;
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class)->withPivot('role');
    }

    /**
     * Can this user act on paid features — their own subscription or payment
     * method, or a teammate's on the given customer account?
     *
     * This is THE subscription test for anything customer-scoped. Team
     * members on a company plan don't carry a subscription themselves; the
     * deploy controller understood that, the EnsureSubscribed middleware ran
     * first and bounced them to pricing anyway.
     */
    public function hasSubscriptionAccess(?Customer $customer = null): bool
    {
        if ($this->subscribed('default')
            || $this->hasDefaultPaymentMethod()
            || $this->subscription_status === 'active') {
            return true;
        }

        if (! $customer) {
            return false;
        }

        return $customer->users()
            ->where(function ($q) {
                $q->where('subscription_status', 'active')
                    ->orWhereNotNull('pm_type')
                    ->orWhereHas('subscriptions', fn ($sq) => $sq->where('stripe_status', 'active'));
            })
            ->exists();
    }

    public function emailInbox()
    {
        return $this->hasOne(\App\Models\EmailInbox::class);
    }

    /**
     * Get the user's primary customer (via customer_id column).
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user's current subscription plan name.
     *
     * @return string
     */
    public function assignedPlan()
    {
        return $this->belongsTo(Plan::class, 'assigned_plan_id');
    }

    public function getSubscriptionPlanAttribute(): string
    {
        // Admin-assigned plan takes priority
        if ($this->assigned_plan_id && $this->assignedPlan) {
            return $this->assignedPlan->name;
        }

        // Then check Cashier subscription
        if ($this->subscribed('default')) {
            $subscription = $this->subscription('default');
            if ($subscription) {
                $plan = Plan::where('stripe_price_id', $subscription->stripe_price)->first();
                if ($plan) {
                    return $plan->name;
                }
            }

            return 'Subscribed';
        }

        return 'Free';
    }

    /**
     * Feature-to-plan mapping: which plan slugs grant access to which features.
     */
    protected static array $featurePlanMap = [
        'competitor_analysis' => ['growth', 'agency'],
        'white_label_reports' => ['agency'],
        'multi_client' => ['agency'],
        'advanced_creative' => ['growth', 'agency'],
        'daily_optimization' => ['growth', 'agency'],
        'war_room' => ['growth', 'agency'],
        'beta_features' => ['agency'],
    ];

    /**
     * Check if the user's current plan includes a given feature.
     */
    public function hasFeature(string $feature): bool
    {
        // Admins always have access
        if ($this->hasRole('admin')) {
            return true;
        }

        $allowedSlugs = static::$featurePlanMap[$feature] ?? [];

        if (empty($allowedSlugs)) {
            return false;
        }

        $plan = $this->resolveCurrentPlan();

        return $plan && in_array($plan->slug, $allowedSlugs, true);
    }

    /**
     * Get the ad platforms this user's plan allows.
     */
    public function allowedPlatforms(): array
    {
        if ($this->hasRole('admin')) {
            return ['google', 'facebook', 'microsoft', 'linkedin'];
        }

        $plan = $this->resolveCurrentPlan();
        $slug = $plan?->slug ?? 'free';

        return match ($slug) {
            'free' => ['google'],
            'starter' => [$this->starter_platform ?? 'google'],
            default => ['google', 'facebook', 'microsoft', 'linkedin'],
        };
    }

    /**
     * Resolve the user's current Plan model (assigned or Stripe subscription).
     */
    public function resolveCurrentPlan(): ?Plan
    {
        if ($this->assigned_plan_id && $this->assignedPlan) {
            return $this->assignedPlan;
        }

        if ($this->subscribed('default')) {
            $subscription = $this->subscription('default');
            if ($subscription) {
                return Plan::where('stripe_price_id', $subscription->stripe_price)->first();
            }
        }

        return Plan::where('slug', 'free')->first();
    }

    /**
     * Cashier's Invoice, not an App\Models\Invoice — no such model exists, so
     * the unqualified hint resolved to a missing class and this would have
     * fatalled on the first call. It has no callers yet, which is why nobody
     * found out.
     */
    public function sendInvoice(\Laravel\Cashier\Invoice $invoice)
    {
        Mail::to($this->email)->send(new InvoiceCreated($this, $invoice->total(), $invoice->date()->toFormattedDateString()));
    }

    /**
     * The roles that belong to the user.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get unread notifications for the user.
     */
    public function unreadNotifications()
    {
        return $this->notifications()->whereNull('read_at');
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * May this user reach the admin console at all?
     *
     * Support staff need to see customers and campaigns to answer questions.
     * They do not need to delete customers, rotate the platform's MCC
     * credentials or change plan pricing — and until now there was no way to
     * give them the first without the second, because 'admin' was the only role
     * that existed.
     */
    /**
     * Has this user finished setting up a second factor?
     *
     * Enrolment is only complete once a code has been verified — a secret that
     * was generated but never confirmed means the authenticator app may never
     * have been set up, and locking someone out on the strength of it would be
     * wrong.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function canAccessAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasRole('support');
    }

    /**
     * Everyone who should receive platform-wide admin alerts.
     *
     * Resolved from the admin role at send time rather than from a configured
     * address list, so it cannot drift: promote someone and they start
     * receiving support tickets, demote them and they stop. It replaces
     * config('app.admin_email'), which was a single address — every ticket
     * went to one inbox regardless of who was actually running support.
     *
     * 'support' is deliberately excluded: support staff work the admin queue
     * directly, and the point of this is to page the people accountable for it.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function admins(): \Illuminate\Support\Collection
    {
        return static::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->whereNull('banned_at')
            ->whereNotNull('email')
            ->get();
    }

    /**
     * Is this user a full admin, as opposed to support?
     *
     * The distinction guards destructive and credential-bearing actions. It is
     * deliberately not "not support": a user with neither role should fail both
     * checks rather than pass this one.
     */
    public function isFullAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Check if the user is an owner of a specific customer.
     */
    public function isOwnerOf(Customer $customer): bool
    {
        return $this->customers()->where('customer_id', $customer->id)->wherePivot('role', 'owner')->exists();
    }
}
