<?php

declare(strict_types=1);

namespace App\Support\Csp\Policies;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class CustomPolicy extends Basic
{
    public function configure(Policy $policy): void
    {
        // Don't call parent::configure() because Basic preset adds a nonce to STYLE,
        // which completely breaks 'unsafe-inline' for style attributes (required by Filament).

        // Replicate Basic preset but without style nonce
        $policy
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME, Keyword::SELF)
            ->add(Directive::IMG, Keyword::SELF)
            ->add(Directive::MEDIA, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::SCRIPT, Keyword::SELF)
            ->add(Directive::STYLE, Keyword::SELF)
            ->addNonce(Directive::SCRIPT);

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
            ->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
    }

    protected function configureProduction(Policy $policy): void
    {
        // Fix for AlpineJS (needs strict nonce AND unsafe-eval)
        $policy->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL);

        // Fix for Filament Style Attributes (needs unsafe-inline WITHOUT nonce)
        $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
    }

    protected function configureExternalResources(Policy $policy): void
    {
        $policy
            ->add(Directive::STYLE, 'https://fonts.googleapis.com')
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::IMG, 'data:')
            ->add(Directive::IMG, 'https://ui-avatars.com')
            ->add(Directive::FONT, 'https://fonts.bunny.net')
            ->add(Directive::FONT, 'https://fonts.gstatic.com')
            ->add(Directive::FONT, 'data:');
    }
}
