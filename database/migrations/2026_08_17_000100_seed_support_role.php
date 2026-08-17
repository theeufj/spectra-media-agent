<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * A role between "can do everything" and "cannot see the console".
 *
 * Production had exactly two roles, admin and user, with four admins and zero
 * users. Anyone who needed to look at AI costs or answer a support ticket also
 * had the ability to delete customers and rotate the MCC credentials the whole
 * platform authenticates with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::unguarded(fn () => Role::firstOrCreate(['name' => 'support']));
    }

    public function down(): void
    {
        Role::where('name', 'support')->delete();
    }
};
