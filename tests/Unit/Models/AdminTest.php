<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Admin;
use ReflectionClass;
use Spatie\Activitylog\LogOptions;
use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_get_activitylog_options(): void
    {
        $admin = new Admin();
        $options = $admin->getActivitylogOptions();

        $this->assertInstanceOf(LogOptions::class, $options);

        // Reflection is required to access the internal state of LogOptions
        // since the properties are protected and it has no getters.
        $reflection = new ReflectionClass($options);

        $logAttributesProp = $reflection->getProperty('logAttributes');
        $logAttributesProp->setAccessible(true);
        $this->assertEquals(['name', 'email'], $logAttributesProp->getValue($options));

        $logOnlyDirtyProp = $reflection->getProperty('logOnlyDirty');
        $logOnlyDirtyProp->setAccessible(true);
        $this->assertTrue($logOnlyDirtyProp->getValue($options));

        $submitEmptyLogsProp = $reflection->getProperty('submitEmptyLogs');
        $submitEmptyLogsProp->setAccessible(true);
        $this->assertFalse($submitEmptyLogsProp->getValue($options));
    }
}
