<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A security header the browser rejects is worse than no header: the response
 * looks protected to anyone reading the middleware, and protects nothing.
 *
 * `Permissions-Policy` carried `vr=()` — a draft name that never made it into
 * the registry. Chrome dropped the token and said so on every single response:
 * 154 times in one browser-test run, unnoticed, because assertNoConsoleExceptions
 * only looks at SEVERE and this arrives as a warning. WebXR was never denied.
 *
 * Asserting the header string would only restate the middleware. This asks the
 * browser, which is the only thing whose opinion decides whether the policy
 * applies — and it generalises: any header the app grows later is covered the
 * day it is added.
 */
class SecurityHeaderSyntaxTest extends DuskTestCase
{
    public function test_the_browser_accepts_every_security_header_the_app_sends(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs(User::factory()->create())
                ->visit(route('dashboard'))
                ->waitFor('#main-content', 30);

            /**
             * Chrome reports a malformed response header as
             * "Error with <name> header: ...", at warning level and with
             * source "security" — never as an exception, which is why nothing
             * caught this.
             */
            $rejected = collect($browser->driver->manage()->getLog('browser'))
                ->map(fn (array $log): string => (string) ($log['message'] ?? ''))
                ->filter(fn (string $message): bool => str_contains($message, 'Error with')
                    && str_contains($message, 'header'))
                ->values()
                ->all();

            $this->assertSame(
                [],
                $rejected,
                "The browser rejected a header this app sends:\n".implode("\n", $rejected)
            );
        });
    }
}
