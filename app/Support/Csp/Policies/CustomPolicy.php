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
            ->add(Directive::STYLE, Keyword::SELF);

        if (! app()->environment('local')) {
            $policy->addNonce(Directive::SCRIPT);
            $policy->addNonce(Directive::STYLE); // Re-enable style nonces globally for security
        }

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
            ->add(Directive::SCRIPT, Keyword::UNSAFE_INLINE)
            ->add(Directive::SCRIPT, 'http://localhost:5173')
            ->add(Directive::STYLE, Keyword::UNSAFE_INLINE)
            ->add(Directive::STYLE, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'ws://localhost:5173');
    }

    protected function configureProduction(Policy $policy): void
    {
        // Deliberate security tradeoff: AlpineJS requires 'unsafe-eval' to execute
        // inline scripts. Refactoring to the CSP build of Alpine is not feasible
        // as it is bundled and managed internally by Filament.
        $policy->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL);

        // Fix for Filament Style Attributes: Instead of adding 'unsafe-inline' to
        // the global style-src directive (which breaks nonce protection for all <style> tags),
        // we use style-src-attr to specifically allow inline attributes on elements,
        // while preserving nonce requirements for actual style tags.
        // This requires CSP level 3 browser support.
        $policy->add('style-src-attr', Keyword::UNSAFE_INLINE);
    }

    protected function configureExternalResources(Policy $policy): void
    {
        $policy
            ->add(Directive::STYLE, 'https://fonts.googleapis.com')
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::IMG, 'data:')
            ->add(Directive::IMG, 'https://ui-avatars.com')
            ->add(Directive::IMG, 'https://www.svgrepo.com')
            ->add(Directive::FONT, 'https://fonts.bunny.net')
            ->add(Directive::FONT, 'https://fonts.gstatic.com')
            ->add(Directive::FONT, 'data:')
            ->add(Directive::CONNECT, 'https://fcm.googleapis.com')
            ->add(Directive::CONNECT, 'https://updates.push.apple.com')
            ->add(Directive::CONNECT, 'https://*.notify.windows.com');
    }
}
