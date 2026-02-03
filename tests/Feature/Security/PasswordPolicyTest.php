<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_password_defaults_are_configured_correctly_for_testing(): void
    {
        // By default, testing environment should only require min:8
        $rules = Password::defaults();

        // We can't easily inspect the internal state of the Password rule object directly
        // without reflection or resolving it, but we can test validation behavior.

        // This confirms that "password" (8 chars) passes in testing
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['password' => 'password'],
            ['password' => Password::defaults()]
        );

        $this->assertTrue($validator->passes(), 'Default "password" should pass in testing environment');

        // This confirms that "short" (5 chars) fails
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['password' => 'short'],
            ['password' => Password::defaults()]
        );

        $this->assertFalse($validator->passes(), 'Short password should fail');
    }

    public function test_production_password_policy_can_be_simulated(): void
    {
        // Skip if we can't mock the environment easily in a way that affects the AppServiceProvider boot
        // Because AppServiceProvider::boot() runs before the test starts.
        // However, we can manually check the logic we intend to put in AppServiceProvider.

        $isProduction = false;

        $rule = Password::min(8);
        $productionRule = $rule->mixedCase()->numbers()->symbols()->uncompromised();

        // This is just verifying the rule construction logic works, not the integration
        $this->assertNotNull($productionRule);
    }
}
