<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;

Carbon::setTestNow(Carbon::create(2026, 3, 26, 12, 0, 0));

var_dump(Carbon::now());
var_dump(Carbon::now()->addHours(2));
