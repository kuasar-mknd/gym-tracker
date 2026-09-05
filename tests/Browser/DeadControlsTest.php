<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Controls that render, look enabled, and do nothing. They are the worst kind of
 * bug to leave in place because the interface promises something it cannot do,
 * and nothing anywhere reports a failure — no console error, no 4xx, no test.
 */
class DeadControlsTest extends DuskTestCase
{
    /**
     * The button was wired to changeMonth(0), which resolves to the month
     * already on screen. It only appears once you have navigated away, so its
     * single purpose was the one thing it did not do.
     */
    public function test_the_today_button_returns_to_the_current_month(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $currentYear = (string) now()->year;

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('calendar.index', ['year' => 2020, 'month' => 3]))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForTextIn('@calendar-heading', '2020', 15)
                ->click('@calendar-today')
                ->waitForTextIn('@calendar-heading', $currentYear, 15);

            $heading = $browser->text('@calendar-heading');

            $this->assertStringContainsString($currentYear, $heading);
            $this->assertStringNotContainsString('2020', $heading);
        });
    }

    /**
     * The list paginates at 20. The pagination block was a conditional empty div
     * wrapping a "add this if required" comment, so notification 21 onwards
     * existed in the database and could not be reached from the interface.
     */
    public function test_notifications_past_the_first_page_are_reachable(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            // Written straight to the table rather than through notify(): unread
            // AchievementUnlocked notifications feed latest_achievement, which pops
            // the global celebration overlay and swallows clicks on this page.
            $user->notifications()->createMany(
                collect(range(1, 25))->map(fn (int $index): array => [
                    'id' => (string) Str::uuid(),
                    'type' => PersonalRecordAchieved::class,
                    'data' => [
                        'type' => 'personal_record',
                        'title' => "Record {$index}",
                        'message' => "Nouveau record numéro {$index}.",
                    ],
                    'read_at' => null,
                ])->all()
            );

            $firstPageIds = $user->notifications()->take(20)->pluck('id')->all();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('notifications.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@notifications-next', 15);

            // Centred first: the floating bottom nav sits over the last 88px of
            // the viewport, and WebDriver's own scrolling stops short of the
            // padding that keeps the control clear of it for a real user.
            $browser->clickWhenSettled('[dusk="notifications-next"]');
            $browser->waitForTextIn('nav[aria-label="Pagination des notifications"]', 'Page 2', 15);

            $renderedIds = $browser->script(
                'return Array.from(document.querySelectorAll("[data-notification-id]"))'
                .'.map(el => el.getAttribute("data-notification-id"));'
            )[0];

            $this->assertNotEmpty($renderedIds, 'Page 2 must render the remaining notifications.');
            $this->assertEmpty(
                array_intersect($renderedIds, $firstPageIds),
                'Page 2 must show different notifications than page 1.'
            );
        });
    }
}
