<?php
$content = file_get_contents('tests/Feature/Services/GoalServiceTest.php');

$content = str_replace(
    "\$goal2 = Goal::factory()->create([\n        'user_id' => \$user->id,\n        'type' => GoalType::Frequency,\n        'start_value' => 0,\n        'target_value' => 5,\n        'current_value' => 10, // overshoot\n    ]);\n    \n    \$this->service->updateGoalProgress(\$goal2);",
    "\$goal2 = Goal::factory()->create([\n        'user_id' => \$user->id,\n        'type' => GoalType::Measurement,\n        'measurement_type' => 'weight',\n        'start_value' => 0,\n        'target_value' => 5,\n        'current_value' => 10, // overshoot\n    ]);\n    \n    \App\Models\BodyMeasurement::factory()->create([\n        'user_id' => \$user->id,\n        'weight' => 10,\n    ]);\n    \n    \$this->service->updateGoalProgress(\$goal2);",
    $content
);

file_put_contents('tests/Feature/Services/GoalServiceTest.php', $content);
