<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Workouts;

use App\Actions\Workouts\UpdateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UpdateWorkoutActionTest extends TestCase
{
    use RefreshDatabase;

    protected StatsService $statsService;
    protected UpdateWorkoutAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = Mockery::mock(StatsService::class);
        $this->action = new UpdateWorkoutAction($this->statsService);
    }

    public function test_execute_clears_full_stats_if_started_at_changes(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);

        $this->statsService->shouldReceive('clearWorkoutRelatedStats')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));
        $this->statsService->shouldReceive('clearDashboardCache')->never();

        $this->action->execute($workout, ['started_at' => now()->toDateTimeString()]);
    }

    public function test_execute_clears_dashboard_and_name_stats_if_name_changes(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

        $this->statsService->shouldReceive('clearWorkoutRelatedStats')->never();
        $this->statsService->shouldReceive('clearDashboardCache')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));
        $this->statsService->shouldReceive('clearWorkoutNameDependentStats')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));
        $this->statsService->shouldReceive('clearWorkoutDurationDependentStats')->never();

        $this->action->execute($workout, ['name' => 'New Name']);
    }

    public function test_execute_clears_dashboard_if_notes_changes(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'notes' => 'Old Notes']);

        $this->statsService->shouldReceive('clearWorkoutRelatedStats')->never();
        $this->statsService->shouldReceive('clearDashboardCache')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));
        $this->statsService->shouldReceive('clearWorkoutNameDependentStats')->never();
        $this->statsService->shouldReceive('clearWorkoutDurationDependentStats')->never();

        $this->action->execute($workout, ['notes' => 'New Notes']);
    }

    public function test_execute_clears_dashboard_and_duration_stats_if_ended_at_changes(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        $this->statsService->shouldReceive('clearWorkoutRelatedStats')->never();
        $this->statsService->shouldReceive('clearDashboardCache')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));
        $this->statsService->shouldReceive('clearWorkoutNameDependentStats')->never();
        $this->statsService->shouldReceive('clearWorkoutDurationDependentStats')->once()->with(Mockery::on(fn($arg) => $arg->id === $user->id));

        $this->action->execute($workout, ['is_finished' => true]);
    }
}
