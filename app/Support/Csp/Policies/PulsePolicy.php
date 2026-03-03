<?php

declare(strict_types=1);

namespace App\Support\Csp\Policies;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class PulsePolicy extends Basic
{
    public function configure(Policy $policy): void
    {
        parent::configure($policy);

        $policy
            ->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->addNonce(Directive::SCRIPT)
            ->addNonce(Directive::STYLE)
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::FONT, [Keyword::SELF, 'https://fonts.bunny.net'])
            ->add(Directive::IMG, [Keyword::SELF, 'data:', 'https:']);
    }
}
