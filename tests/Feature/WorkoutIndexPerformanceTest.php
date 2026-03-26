<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkoutIndexPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workouts_index_has_consolidated_deferred_prop(): void
    {
        $user = User::factory()->create();

        // 1. Initial Load: deferredData should be deferred (missing from initial props)
        $this->actingAs($user)
            ->get('/workouts')
            ->assertStatus(200)
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Workouts/Index')
                    ->missing('deferredData')
                    ->missing('exercises')
                    ->missing('chartData')
                    ->missing('monthlyFrequency')
            );
    }
}
