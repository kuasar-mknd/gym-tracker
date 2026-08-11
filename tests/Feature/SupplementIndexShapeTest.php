<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The index action hand-rolled its own array and it matched nothing. It sent
 * icon, current_log, unit and daily_goal — four keys read by no template, no
 * test and no other action — and left out brand, dosage, servings_remaining,
 * low_stock_threshold and last_taken_at, all five of which the card renders.
 *
 * So the stock figure was blank, the low-stock colouring compared undefined,
 * and the edit form pre-filled from nothing and was then rejected by the
 * update request for missing required fields.
 *
 * This pins the contract between the action and the page rather than the exact
 * key list, so it stays useful as the card grows.
 */
final class SupplementIndexShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_receives_every_field_its_card_renders(): void
    {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $user->id,
            'name' => 'Whey',
            'brand' => 'MyProtein',
            'dosage' => '30g',
            'servings_remaining' => 12,
            'low_stock_threshold' => 5,
        ]);
        SupplementLog::create([
            'user_id' => $user->id,
            'supplement_id' => $supplement->id,
            'quantity' => 1,
            'consumed_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('supplements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Supplements/Index')
                ->where('supplements.0.name', 'Whey')
                ->where('supplements.0.brand', 'MyProtein')
                ->where('supplements.0.dosage', '30g')
                ->where('supplements.0.servings_remaining', 12)
                ->where('supplements.0.low_stock_threshold', 5)
                ->has('supplements.0.last_taken_at')
            );
    }

    /**
     * Consuming decrements the stock server-side, but the page could not show
     * it because the figure was never sent. This follows the number the user
     * actually sees.
     */
    public function test_the_stock_the_page_shows_follows_a_dose_being_taken(): void
    {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $user->id,
            'servings_remaining' => 3,
        ]);

        $this->actingAs($user)->post(route('supplements.consume', ['supplement' => $supplement->id]));

        $this->actingAs($user)
            ->get(route('supplements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('supplements.0.servings_remaining', 2));
    }

    /**
     * The edit form fills itself from these props. When they were absent the
     * PUT went out without them and the update request refused it, so a
     * supplement could not be edited at all.
     */
    public function test_a_supplement_can_be_edited_from_what_the_page_was_given(): void
    {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $user->id,
            'name' => 'Créatine',
            'servings_remaining' => 30,
            'low_stock_threshold' => 5,
        ]);

        $props = $this->actingAs($user)
            ->get(route('supplements.index'))
            ->viewData('page')['props']['supplements'][0];

        $this->actingAs($user)
            ->put(route('supplements.update', ['supplement' => $supplement->id]), [
                'name' => 'Créatine monohydrate',
                'brand' => $props['brand'],
                'dosage' => $props['dosage'],
                'servings_remaining' => $props['servings_remaining'],
                'low_stock_threshold' => $props['low_stock_threshold'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Créatine monohydrate', $supplement->refresh()->name);
    }
}
