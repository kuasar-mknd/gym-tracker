<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The notification preferences panel used to decide whether push was on by
 * reading Notification.permission — a browser fact — when the question is
 * whether *we* hold a subscription. The browser grants permission before the
 * subscription is ever sent to us, so a failed POST left the panel showing its
 * "push is on" state: the activation banner gone, the "Envoyer aussi en Push"
 * checkboxes offered, and no path back to retry.
 *
 * This pins the prop that replaced the guess.
 */
final class ProfilePushSubscriptionStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_a_subscription_is_reported_as_unregistered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Profile/Edit')
                ->where('hasPushSubscription', false)
            );
    }

    public function test_a_stored_subscription_is_reported_to_the_page(): void
    {
        $user = User::factory()->create();

        $user->updatePushSubscription(
            'https://push.example.com/endpoint',
            'a-public-key',
            'an-auth-token'
        );

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Profile/Edit')
                ->where('hasPushSubscription', true)
            );
    }

    /**
     * One user's subscription must not switch the panel on for another — this
     * reads through the relationship rather than the table.
     */
    public function test_another_users_subscription_does_not_count(): void
    {
        $subscribed = User::factory()->create();
        $subscribed->updatePushSubscription('https://push.example.com/other', 'key', 'token');

        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('hasPushSubscription', false));
    }
}
