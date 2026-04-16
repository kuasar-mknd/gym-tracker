<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Admin;
use Spatie\Activitylog\LogOptions;
use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_get_activitylog_options(): void
    {
        $admin = new Admin();
        $options = $admin->getActivitylogOptions();

        $this->assertInstanceOf(LogOptions::class, $options);

        // Properties are public in Spatie\Activitylog\LogOptions
        $this->assertEquals(['name', 'email'], $options->logAttributes);
        $this->assertTrue($options->logOnlyDirty);
        $this->assertFalse($options->submitEmptyLogs);
    }
}
