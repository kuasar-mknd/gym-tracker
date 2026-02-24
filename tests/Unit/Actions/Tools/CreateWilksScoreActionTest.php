<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tools;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CreateWilksScoreActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateWilksScoreAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateWilksScoreAction();
    }

    #[DataProvider('wilksDataProvider')]
    public function test_calculate_wilks_formula(float $bw, float $lifted, string $gender, float $expected): void
    {
        $reflection = new ReflectionClass(CreateWilksScoreAction::class);
        $method = $reflection->getMethod('calculateWilks');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->action, [$bw, $lifted, $gender]);

        $this->assertEquals($expected, $result);
    }

    public static function wilksDataProvider(): array
    {
        return [
            'male_80kg_500kg' => [80.0, 500.0, 'male', 341.35],
            'male_100kg_600kg' => [100.0, 600.0, 'male', 365.15],
            'female_60kg_300kg' => [60.0, 300.0, 'female', 334.47],
            'female_75kg_400kg' => [75.0, 400.0, 'female', 380.26],
        ];
    }

    public function test_execute_converts_lbs_to_kg(): void
    {
        $user = User::factory()->create();

        // 176.3696 lbs is approx 80kg (176.3696 / 2.20462 = 80)
        // 1102.31 lbs is approx 500kg (1102.31 / 2.20462 = 500)
        $data = [
            'body_weight' => 176.3696,
            'lifted_weight' => 1102.31,
            'gender' => 'male',
            'unit' => 'lbs',
        ];

        $wilksScore = $this->action->execute($user, $data);

        $this->assertEquals(341.35, $wilksScore->score);
        $this->assertEquals(176.3696, $wilksScore->body_weight);
        $this->assertEquals('lbs', $wilksScore->unit);
    }

    public function test_execute_saves_to_database(): void
    {
        $user = User::factory()->create();
        $data = [
            'body_weight' => 80.0,
            'lifted_weight' => 500.0,
            'gender' => 'male',
            'unit' => 'kg',
        ];

        $wilksScore = $this->action->execute($user, $data);

        $this->assertDatabaseHas('wilks_scores', [
            'id' => $wilksScore->id,
            'user_id' => $user->id,
            'score' => 341.35,
        ]);
    }
}
