<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\WaterLog;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Three features that failed without ever saying so: the request was rejected,
 * no error was rendered, and the UI looked idle. Feature tests posting correct
 * payloads pass right through this class of bug, so these drive the real UI.
 */
class SilentFormFailuresTest extends DuskTestCase
{
    public function test_logging_water_actually_persists(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('tools.water.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('button[aria-label="Ajouter 250ml"]', 15)
                ->click('button[aria-label="Ajouter 250ml"]');

            // The form omitted consumed_at, which is required|date server-side, so
            // every tap 422'd and the page rendered no error at all.
            $this->waitForDatabase(fn (): bool => WaterLog::where('user_id', $user->id)->exists());

            $this->assertSame(
                1,
                WaterLog::where('user_id', $user->id)->count(),
                'Tapping a water preset must create a log.'
            );
        });
    }

    public function test_notification_preferences_save_button_submits(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resize(1280, 900)
                ->visit(route('profile.edit'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@save-notification-preferences', 15)
                // GlassButton defaults to type="button"; this must stay a submit or
                // the click silently bypasses the form handler.
                ->assertAttribute('@save-notification-preferences', 'type', 'submit');
        });
    }

    public function test_every_fasting_type_is_selectable(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('tools.fasting.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@fasting-type-select', 15);

            // Objects bound as option values serialise to "[object Object]", which
            // made every entry identical and broke startFast().
            $values = $browser->script(
                "return Array.from(document.querySelectorAll('[dusk=\"fasting-type-select\"] option'))"
                .'.map(o => o.value);'
            )[0];

            $this->assertNotContains('[object Object]', $values);
            $this->assertSame($values, array_unique($values), 'Option values must be distinct.');
        });
    }
}
