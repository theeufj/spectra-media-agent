<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (!DB::table('enabled_platforms')->where('slug', 'microsoft')->exists()) {
            DB::table('enabled_platforms')->insert([
                'name' => 'Microsoft',
                'slug' => 'microsoft',
                'description' => 'Microsoft/Bing Ads platform',
                'is_enabled' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!DB::table('enabled_platforms')->where('slug', 'linkedin')->exists()) {
            DB::table('enabled_platforms')->insert([
                'name' => 'LinkedIn',
                'slug' => 'linkedin',
                'description' => 'LinkedIn Ads platform',
                'is_enabled' => true,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('enabled_platforms')->whereIn('slug', ['microsoft', 'linkedin'])->delete();
    }
};
