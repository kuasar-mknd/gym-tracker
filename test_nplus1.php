<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::enableQueryLog();

$command = new \App\Console\Commands\TrainingReminderCommand();
$command->handle();

$log = DB::getQueryLog();
echo 'Queries: '.count($log)."\n";
foreach ($log as $q) {
    echo $q['query']."\n";
}
