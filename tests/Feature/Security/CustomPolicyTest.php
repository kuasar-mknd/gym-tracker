<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\Csp\Policies\CustomPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Tests\TestCase;

class CustomPolicyTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function getDirectivesFromPolicy(Policy $policy): array
    {
        $directives = new ReflectionClass($policy)->getProperty('directives')->getValue($policy);

        $this->assertIsArray($directives);

        /** @var array<string, list<string>> $directives */
        return $directives;
    }

    private function getDirectiveKey(Directive $directive): string
    {
        return $directive->value;
    }

    private function formatKeyword(Keyword $keyword): string
    {
        return "'{$keyword->value}'";
    }

    public function test_custom_policy_has_correct_base_directives(): void
    {
        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::BASE)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::DEFAULT)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::FONT)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::FORM_ACTION)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::FRAME)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::MEDIA)]);
        $this->assertContains($this->formatKeyword(Keyword::NONE), $directives[$this->getDirectiveKey(Directive::OBJECT)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains($this->formatKeyword(Keyword::SELF), $directives[$this->getDirectiveKey(Directive::STYLE)]);
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
        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_EVAL), $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertContains('http://localhost:5173', $directives[$this->getDirectiveKey(Directive::SCRIPT)]);

        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::STYLE)]);
        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::STYLE_ATTR)]);
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

        // L'application Vue n'a pas besoin d'unsafe-eval : hors panneau, il ne sort pas.
        $this->assertNotContains($this->formatKeyword(Keyword::UNSAFE_EVAL), $directives[$this->getDirectiveKey(Directive::SCRIPT)]);
        $this->assertNotContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::SCRIPT)]); // unsafe-inline is local only

        // In production, we allow unsafe-inline for both elements and attributes to support Filament
        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::STYLE)]);
        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_INLINE), $directives[$this->getDirectiveKey(Directive::STYLE_ATTR)]);
    }

    /**
     * Alpine, embarqué par Filament, compile ses expressions avec new Function :
     * le panneau garde unsafe-eval, et lui seul.
     */
    public function test_production_policy_allows_unsafe_eval_on_the_filament_panel_only(): void
    {
        Config::set('app.env', 'production');
        $this->app['env'] = 'production';
        $this->app->instance('request', Request::create('/backoffice/login'));

        $policy = new Policy();
        new CustomPolicy()->configure($policy);

        $this->assertContains($this->formatKeyword(Keyword::UNSAFE_EVAL), $this->getDirectivesFromPolicy($policy)[$this->getDirectiveKey(Directive::SCRIPT)]);
    }

    public function test_custom_policy_has_correct_external_resources(): void
    {
        $policy = new Policy();
        $customPolicy = new CustomPolicy();
        $customPolicy->configure($policy);

        $directives = $this->getDirectivesFromPolicy($policy);

        /**
         * bunny.net is still allowed because Horizon, Telescope, Pulse and
         * Filament each pull their dashboard font from it, and none of those
         * views are ours to change.
         */
        $this->assertContains('https://fonts.bunny.net', $directives[$this->getDirectiveKey(Directive::STYLE)]);

        $this->assertContains('data:', $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains('https://ui-avatars.com', $directives[$this->getDirectiveKey(Directive::IMG)]);
        $this->assertContains('https://www.svgrepo.com', $directives[$this->getDirectiveKey(Directive::IMG)]);

        $this->assertContains('https://fonts.bunny.net', $directives[$this->getDirectiveKey(Directive::FONT)]);
        $this->assertContains('data:', $directives[$this->getDirectiveKey(Directive::FONT)]);

        /**
         * The app's own fonts are served from this origin now, so nothing it
         * renders has any reason to reach Google. Asserted as absent rather
         * than simply dropped from the list above: an allowance that creeps
         * back in is exactly the kind of change that passes review unnoticed.
         */
        $this->assertNotContains('https://fonts.googleapis.com', $directives[$this->getDirectiveKey(Directive::STYLE)]);
        $this->assertNotContains('https://fonts.gstatic.com', $directives[$this->getDirectiveKey(Directive::FONT)]);

        $this->assertContains('https://fcm.googleapis.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains('https://updates.push.apple.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
        $this->assertContains('https://*.notify.windows.com', $directives[$this->getDirectiveKey(Directive::CONNECT)]);
    }
}
