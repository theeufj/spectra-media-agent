<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $user = \App\Models\User::first();
    echo "DB works! First user email: " . ($user ? $user->email : 'None found') . "\n";
} catch (\Exception $e) {
    echo "DB error: " . $e->getMessage() . "\n";
}
