<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::create(['name' => 'admin']);
        Role::create(['name' => 'user']);

        $adminUser = User::where('email', 'theeufj@gmail.com')->first();
        if ($adminUser) {
            $adminUser->roles()->attach($adminRole);
        }
    }
}
