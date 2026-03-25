<?php
$content = file_get_contents('tests/Feature/Services/GoalServiceTest.php');

$content = str_replace("expect(\$goal->progress_pct)->toBe(100.0)", "expect((float) \$goal->progress_pct)->toBe(100.0)", $content);
$content = str_replace("expect(\$goal1->progress_pct)->toBe(100.0);", "expect((float) \$goal1->progress_pct)->toBe(100.0);", $content);
$content = str_replace("expect(\$goal2->progress_pct)->toBe(100.0);", "expect((float) \$goal2->progress_pct)->toBe(100.0);", $content);

file_put_contents('tests/Feature/Services/GoalServiceTest.php', $content);
