<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable; // Import the Billable trait provided by Laravel Cashier.

class User extends Authenticatable
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
        ];
    }

    /**
     * Get the third-party connections for the user.
     */
    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function knowledgeBases()
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    /**
     * Get the customer record associated with the user.
     */
    public function customer()
    {
        return $this->hasOne(Customer::class);
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
}

