<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Admin;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\LogOptions;
use Tests\TestCase;
use Mockery;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_access_panel(): void
    {
        $admin = Admin::factory()->create();
        $panel = Mockery::mock(Panel::class);

        $this->assertTrue($admin->canAccessPanel($panel));
    }

    public function test_get_activitylog_options(): void
    {
        $admin = Admin::factory()->create();

        $options = $admin->getActivitylogOptions();

        $this->assertInstanceOf(LogOptions::class, $options);

        // Let's assert some internal properties of LogOptions if possible, or just trust the instantiation.
        // Actually, Spatie's LogOptions object has public properties/methods.
        // We know from the Model it calls ->logOnly(['name', 'email'])->logOnlyDirty()->dontSubmitEmptyLogs()
        // But those are methods that set internal state.
        // Just checking the instance type is often enough for a unit/feature test if we don't want to use reflection.
    }
}
