<?php

namespace App\Support\Csp\Policies;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class CustomPolicy extends Basic
{
    public function configure(Policy $policy): void
    {
        parent::configure($policy);

        if (app()->environment('local', 'testing')) {
            $this->configureLocal($policy);
        } else {
            $this->configureProduction($policy);
        }

        $this->configureExternalResources($policy);
    }

    protected function configureLocal(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, 'http://localhost:5173')
            ->add(Directive::DEFAULT, 'ws://localhost:5173')
            ->add(Directive::SCRIPT, 'http://localhost:5173')
            ->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->add(Directive::STYLE, 'http://localhost:5173')
            ->add(Directive::STYLE, Keyword::UNSAFE_INLINE)
            ->add(Directive::IMG, 'http://localhost:5173')
            ->add(Directive::IMG, 'blob:')
            ->add(Directive::FONT, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'ws://localhost:5173');
    }

    protected function configureProduction(Policy $policy): void
    {
        $policy
            ->addNonce(Directive::SCRIPT)
            ->add(Directive::STYLE, Keyword::UNSAFE_INLINE); // Keep for now as many components might rely on it
    }

    protected function configureExternalResources(Policy $policy): void
    {
        $policy
            ->add(Directive::STYLE, 'https://fonts.googleapis.com')
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::IMG, 'https:')
            ->add(Directive::IMG, 'data:')
            ->add(Directive::IMG, 'https://ui-avatars.com')
            ->add(Directive::FONT, 'https://fonts.bunny.net')
            ->add(Directive::FONT, 'https://fonts.gstatic.com')
            ->add(Directive::FONT, 'data:')
            ->add(Directive::CONNECT, 'https:')
            ->add(Directive::FRAME, 'https:');
    }
}
