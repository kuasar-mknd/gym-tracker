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

    public function test_workouts_index_sends_the_list_and_defers_nothing(): void
    {
        $user = User::factory()->create();

        $reponse = $this->actingAs($user)->get('/workouts');

        $reponse->assertOk()->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Workouts/Index')
                ->has('workouts')
                ->missing('deferredData')
        );
        expect($reponse->viewData('page')['deferredProps'] ?? [])->toBe([]);
    }
}
