<?php

declare(strict_types=1);

use App\Support\Csp\Policies\PulsePolicy;
use Mockery\MockInterface;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;

describe('configure', function () {
    it('configures the policy correctly', function () {
        $policyMock = Mockery::mock(Policy::class, function (MockInterface $mock) {
            // Because Mockery behaves strictly when expectations are defined,
            // we will simply assert that add() and addNonce() are called with the required parameters
            // at least once, rather than explicitly enumerating every single default basic directive.
            $mock->shouldReceive('add')->with(Directive::SCRIPT, Keyword::UNSAFE_EVAL)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::SCRIPT)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::STYLE)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::STYLE, 'https://fonts.bunny.net')->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::FONT, [Keyword::SELF, 'https://fonts.bunny.net'])->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::IMG, [Keyword::SELF, 'data:', 'https:'])->atLeast()->once()->andReturnSelf();

            // Ignore other calls from parent class so we don't need to assert on all of them
            $mock->shouldReceive('add')->andReturnSelf();
            $mock->shouldReceive('addNonce')->andReturnSelf();
        });

        $pulsePolicy = new PulsePolicy();
        $pulsePolicy->configure($policyMock);

        expect(true)->toBeTrue();
    });
});
