<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use App\Mail\InvoiceCreated;
use App\Models\Plan;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, Billable;

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
        'fbclid',
        'msclid',
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
    public function customers()
    {
        return $this->belongsToMany(Customer::class)->withPivot('role');
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
            'starter' => ['google', 'facebook'],
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

    public function sendInvoice(Invoice $invoice)
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
     *
     * @param string $roleName
     * @return bool
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Check if the user is an owner of a specific customer.
     *
     * @param Customer $customer
     * @return bool
     */
    public function isOwnerOf(Customer $customer): bool
    {
        return $this->customers()->where('customer_id', $customer->id)->wherePivot('role', 'owner')->exists();
    }
}

