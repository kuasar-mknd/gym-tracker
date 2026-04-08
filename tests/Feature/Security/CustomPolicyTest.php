<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\Csp\Policies\CustomPolicy;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Tests\TestCase;

class CustomPolicyTest extends TestCase
{
    private function getDirectivesFromPolicy(Policy $policy): array
    {
        $reflection = new ReflectionClass($policy);
        $property = $reflection->getProperty('directives');
        $property->setAccessible(true);

        return $property->getValue($policy);
    }

    private function getDirectiveKey(mixed $directive): string
    {
        return $directive instanceof \UnitEnum ? $directive->value : (string) $directive;
    }

    public function test_custom_policy_has_correct_base_directives(): void
    {
        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::BASE)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::DEFAULT)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::FONT)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::FORM_ACTION)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::FRAME)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::MEDIA)]);
        $this->assertContains(Keyword::NONE, $directives[$this->getDirectiveKey(Directive::OBJECT)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains(Keyword::SELF, $directives[$this->getDirectiveKey(Directive::STYLE)]);
    }

    public function test_custom_policy_has_correct_local_environment_directives(): void
    {
        Config::set('app.env', 'local');
        $this->app['env'] = 'local';

        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        // Local environment should have unsafe-inline for script and style, plus localhost urls
        $this->assertContains(Keyword::UNSAFE_EVAL, $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains(Keyword::UNSAFE_INLINE, $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains('http://localhost:5173', $directives[$this->getDirectiveKey(Directive::SCRIPT)]);

        $this->assertContains(Keyword::UNSAFE_INLINE, $directives[$this->getDirectiveKey(Directive::STYLE)]);
        $this->assertContains('http://localhost:5173', $directives[$this->getDirectiveKey(Directive::STYLE)]);

        $this->assertContains('http://localhost:5173', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains('ws://localhost:5173', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
    }

    public function test_custom_policy_has_correct_production_environment_directives(): void
    {
        Config::set('app.env', 'production');
        $this->app['env'] = 'production';

        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        // Production environment should have unsafe-eval for script, unsafe-inline for style
        $this->assertContains(Keyword::UNSAFE_EVAL, $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertNotContains(Keyword::UNSAFE_INLINE, $directives[$this->getDirectiveKey(Directive::SCRIPT)]); // unsafe-inline is local only

        $this->assertContains(Keyword::UNSAFE_INLINE, $directives[$this->getDirectiveKey(Directive::STYLE)]);
    }

    public function test_custom_policy_has_correct_external_resources(): void
    {
        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        $this->assertContains('https://fonts.googleapis.com', $directives[$this->getDirectiveKey(Directive::STYLE)]);
        $this->assertContains('https://fonts.bunny.net', $directives[$this->getDirectiveKey(Directive::STYLE)]);

        $this->assertContains('data:', $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains('https://ui-avatars.com', $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains('https://www.svgrepo.com', $directives[$this->getDirectiveKey(Directive::IMG)]);

        $this->assertContains('https://fonts.bunny.net', $directives[$this->getDirectiveKey(Directive::FONT)]);
        $this->assertContains('https://fonts.gstatic.com', $directives[$this->getDirectiveKey(Directive::FONT)]);
        $this->assertContains('data:', $directives[$this->getDirectiveKey(Directive::FONT)]);

        $this->assertContains('https://fcm.googleapis.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains('https://updates.push.apple.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains('https://*.notify.windows.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
    }
}
