<?php

declare(strict_types=1);

use App\Support\Csp\Policies\PulsePolicy;
use Illuminate\Support\Arr;
use Mockery\MockInterface;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;

describe('configure', function (): void {
    it('configures the policy correctly', function (): void {
        $policyMock = Mockery::mock(Policy::class, function (MockInterface $mock): void {
            // Because Mockery behaves strictly when expectations are defined,
            // we will simply assert that add() and addNonce() are called with the required parameters
            // at least once, rather than explicitly enumerating every single default basic directive.
            $mock->shouldReceive('add')->with(Directive::SCRIPT, Keyword::UNSAFE_EVAL)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::SCRIPT)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('addNonce')->with(Directive::STYLE)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)->atLeast()->once()->andReturnSelf();
            $mock->shouldReceive('add')->with(Directive::IMG, [Keyword::SELF, 'data:'])->atLeast()->once()->andReturnSelf();

            // Les autres appels, ceux du préréglage parent, passent sans être
            // énumérés, pourvu qu'ils ne nomment aucune autre origine : ni le
            // site de polices où Pulse prend sa fonte, ni `https:` en img-src,
            // qui admettait toute image de n'importe quel site. Un tel appel ne
            // trouve aucune attente, et Mockery le refuse.
            $mock->shouldReceive('add')->with(Mockery::any(), Mockery::on(
                static fn (mixed $sources): bool => array_filter(
                    Arr::wrap($sources),
                    static fn (mixed $source): bool => is_string($source) && ! str_starts_with($source, "'") && ! in_array($source, ['data:', 'blob:'], true),
                ) === [],
            ))->andReturnSelf();
            $mock->shouldReceive('addNonce')->andReturnSelf();
        });

        $pulsePolicy = new PulsePolicy();
        $pulsePolicy->configure($policyMock);

        expect(true)->toBeTrue();
    });
});
