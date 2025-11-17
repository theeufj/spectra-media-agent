<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\User;

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
