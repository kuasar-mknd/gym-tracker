<?php
$content = file_get_contents('tests/Feature/Services/GoalServiceTest.php');

$content = str_replace("->and(\$goal->progress_pct)->toBe(100.0)", "->and((float) \$goal->progress_pct)->toBe(100.0)", $content);

file_put_contents('tests/Feature/Services/GoalServiceTest.php', $content);
