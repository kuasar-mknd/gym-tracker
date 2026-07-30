<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fasting page offers five presets; the server validates `type` against a
 * fixed list. Nothing connected the two, and the page derived the value by
 * string surgery on the label — label.split(' ')[0] — which happens to be
 * right for '16:8 (Leangains)', '18:6' and '20:4 (Warrior)' and wrong for
 * '24h (OMAD)' and '36h (Monk)': it produced '24h' and '36h', neither of which
 * the rule accepts.
 *
 * So two of the five presets could never start a fast, and the page rendered
 * no error to say why: the button spun and nothing happened.
 *
 * This reads the presets out of the component rather than restating them, so
 * adding a sixth preset with a value the server rejects fails here instead of
 * in production.
 */
final class FastingTypeContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{value: string, hours: int}>
     */
    private function presetsFromComponent(): array
    {
        $source = file_get_contents(resource_path('js/Pages/Tools/Fasting/Index.vue'));

        $this->assertIsString($source, 'The fasting page must be readable.');

        $block = preg_match('/const fastingTypes = \[(.*?)\]/s', $source, $matches) === 1
            ? $matches[1]
            : '';

        $this->assertNotSame('', $block, 'Could not find fastingTypes in the component.');

        preg_match_all("/value:\s*'([^']+)'.*?hours:\s*(\d+)/s", $block, $presets, PREG_SET_ORDER);

        $parsed = array_map(
            fn (array $preset): array => ['value' => $preset[1], 'hours' => (int) $preset[2]],
            $presets
        );

        $this->assertGreaterThanOrEqual(
            5,
            count($parsed),
            'Expected the five fasting presets; the component shape must have changed.'
        );

        return $parsed;
    }

    public function test_every_preset_the_page_offers_can_actually_start_a_fast(): void
    {
        foreach ($this->presetsFromComponent() as $preset) {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => $preset['hours'] * 60,
                'type' => $preset['value'],
            ]);

            $response->assertSessionHasNoErrors();

            $this->assertDatabaseHas(Fast::class, [
                'user_id' => $user->id,
                'type' => $preset['value'],
                'status' => 'active',
            ]);
        }
    }

    /**
     * The two values the old string surgery produced. Kept as an explicit test
     * so the defect is stated rather than implied: if someone ever widens the
     * rule to accept them instead, the same fast would be storable under two
     * spellings, and this fails to say so.
     */
    public function test_the_values_the_page_used_to_send_are_rejected(): void
    {
        foreach (['24h' => 24, '36h' => 36] as $labelDerived => $hours) {
            $user = User::factory()->create();

            $this->actingAs($user)->post(route('tools.fasting.store'), [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => $hours * 60,
                'type' => $labelDerived,
            ])->assertSessionHasErrors('type');
        }

        $this->assertDatabaseCount(Fast::class, 0);
    }

    /**
     * The label is what the user reads and the value is what the server takes;
     * pinning both stops the two drifting apart again.
     */
    public function test_the_presets_cover_the_advertised_durations(): void
    {
        $hours = array_column($this->presetsFromComponent(), 'hours');

        $this->assertSame([16, 18, 20, 24, 36], $hours);
    }
}
