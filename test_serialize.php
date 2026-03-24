<?php

declare(strict_types=1);

require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::first() ?? \App\Models\User::factory()->create();
$workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);

$workout = \App\Models\Workout::find($workout->id);
$action = new \App\Actions\Workouts\FetchWorkoutShowAction();
$data = $action->execute($user, $workout);

$request = \Illuminate\Http\Request::create('/workouts/'.$workout->id, 'GET');
$page = new \Inertia\Response('Workouts/Show', $data, 'app', '1.0');
echo json_encode($page->toResponse($request)->getContent());
