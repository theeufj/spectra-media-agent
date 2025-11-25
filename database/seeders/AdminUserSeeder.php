<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = 'theeufj@gmail.com';
        
        // Find or create the user
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Joshua Theeuf',
                'password' => Hash::make('password'), // Default password, should be changed
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator role with full access']
        );

        if (!$user->hasRole('admin')) {
            $user->roles()->attach($adminRole->id);
            $this->command->info("Admin role assigned to {$email}");
        } else {
            $this->command->info("{$email} already has admin role");
        }

        // Set up Stripe customer ID if not exists
        if (!$user->stripe_id) {
            $user->stripe_id = 'cus_seed_' . uniqid();
            $user->save();
            $this->command->info("Stripe customer ID created for {$email}");
        }

        // Create a subscription in the subscriptions table (Cashier format)
        $subscription = DB::table('subscriptions')->updateOrInsert(
            [
                'user_id' => $user->id,
                'type' => 'default', // Cashier default subscription type
            ],
            [
                'stripe_id' => 'sub_seed_' . uniqid(),
                'stripe_status' => 'active',
                'stripe_price' => env('STRIPE_PRICE_ID', 'price_seed_pro'),
                'quantity' => 1,
                'trial_ends_at' => null,
                'ends_at' => null, // null means active subscription
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->command->info("Active Pro subscription created for {$email}");
        $this->command->info("User setup complete! User has admin access and an active subscription.");
    }
}
