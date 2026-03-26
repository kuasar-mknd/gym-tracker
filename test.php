<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;

$lastRecordedDate = Carbon::now()->startOfDay();
$workoutDate = Carbon::now()->startOfDay();

var_dump($lastRecordedDate->equalTo($workoutDate));
