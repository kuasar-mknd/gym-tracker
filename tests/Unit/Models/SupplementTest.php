<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Supplement;
use Spatie\Activitylog\LogOptions;
use Tests\TestCase;

class SupplementTest extends TestCase
{
    public function test_get_activitylog_options_returns_correct_configuration(): void
    {
        $supplement = new Supplement();
        $options = $supplement->getActivitylogOptions();

        $this->assertInstanceOf(LogOptions::class, $options);

        $this->assertContains('name', $options->logAttributes);
        $this->assertContains('brand', $options->logAttributes);
        $this->assertContains('dosage', $options->logAttributes);
        $this->assertContains('servings_remaining', $options->logAttributes);
        $this->assertContains('low_stock_threshold', $options->logAttributes);

        $this->assertTrue($options->logOnlyDirty);
        $this->assertFalse($options->submitEmptyLogs);
    }
}
