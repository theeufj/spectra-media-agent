<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use App\Mail\InvoiceCreated;

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
        'notification_preferences',
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
     * Get the user's current subscription plan name.
     *
     * @return string
     */
    public function getSubscriptionPlanAttribute(): string
    {
        // 'default' is the name of the subscription in Cashier.
        // You can change this if you use a different name.
        if ($this->subscribed('default')) {
            return 'Spectra Pro';
        }

        return 'Free';
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

