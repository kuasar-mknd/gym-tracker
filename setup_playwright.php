<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = App\Models\User::firstOrNew(['email' => 'test@example.com']);
$user->name = 'Test User';
$user->password = bcrypt('password123');
$user->email_verified_at = now();
$user->save();
