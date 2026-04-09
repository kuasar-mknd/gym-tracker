<?php

declare(strict_types=1);

use App\Support\Csp\Policies\PulsePolicy;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Mockery\MockInterface;

describe('configure', function () {
    it('configures the policy correctly', function () {
        $policyMock = Mockery::mock(Policy::class, function (MockInterface $mock) {
            // PulsePolicy calls
            $mock->shouldReceive('add')->with(Directive::SCRIPT, Keyword::UNSAFE_EVAL)->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::SCRIPT)->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::STYLE)->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::STYLE, 'https://fonts.bunny.net')->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::FONT, [Keyword::SELF, 'https://fonts.bunny.net'])->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::IMG, [Keyword::SELF, 'data:', 'https:'])->once()->andReturnSelf();

            // Ignore parent basic policy calls since they're not part of the scope
            $mock->shouldIgnoreMissing();
        });

        $pulsePolicy = new PulsePolicy();
        $pulsePolicy->configure($policyMock);

        expect(true)->toBeTrue(); // Assertion purely to satisfy pest if mockery assertions are not considered assertions.
    });
});
