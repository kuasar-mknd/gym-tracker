<?php

declare(strict_types=1);

namespace App\Support\Csp\Policies;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Presets\Basic;

class PulsePolicy extends Basic
{
    public function configure(): void
    {
        parent::configure();

        $this
            ->addDirective(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->addNonceForDirective(Directive::SCRIPT)
            ->addNonceForDirective(Directive::STYLE)
            ->addDirective(Directive::STYLE, 'https://fonts.bunny.net')
            ->addDirective(Directive::FONT, [Keyword::SELF, 'https://fonts.bunny.net'])
            ->addDirective(Directive::IMG, [Keyword::SELF, 'data:', 'https:']);
    }
}
