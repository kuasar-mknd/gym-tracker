<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Exercise;
use PHPUnit\Framework\TestCase;

class ExerciseTest extends TestCase
{
    public function test_get_activitylog_options(): void
    {
        $exercise = new Exercise();
        $options = $exercise->getActivitylogOptions();

        $this->assertEquals(['name', 'type', 'category', 'default_rest_time'], $options->logAttributes);
        $this->assertTrue($options->logOnlyDirty);
        $this->assertFalse($options->submitEmptyLogs);
    }
}
