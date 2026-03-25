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
        }

        $requiresUnsafeInlineStyles = $this->requiresUnsafeInlineStyles();

        if (app()->environment('local', 'testing')) {
            $this->configureLocal($policy, $requiresUnsafeInlineStyles);
        } else {
            $this->configureProduction($policy, $requiresUnsafeInlineStyles);
        }

        $this->configureExternalResources($policy);
    }

    protected function requiresUnsafeInlineStyles(): bool
    {
        $request = request();
        if (! $request) {
            return false;
        }

        $isFilament = $request->is('backoffice') || $request->is('backoffice/*');
        $isPulse = $request->is('backoffice/pulse') || $request->is('backoffice/pulse/*');

        return $isFilament && ! $isPulse;
    }

    protected function configureLocal(Policy $policy, bool $requiresUnsafeInlineStyles = false): void
    {
        $policy
            ->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->add(Directive::SCRIPT, Keyword::UNSAFE_INLINE)
            ->add(Directive::SCRIPT, 'http://localhost:5173')
            ->add(Directive::STYLE, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'ws://localhost:5173');

        if ($requiresUnsafeInlineStyles) {
            $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
        } else {
            $policy->addNonce(Directive::STYLE);
        }
    }

    protected function configureProduction(Policy $policy, bool $requiresUnsafeInlineStyles = false): void
    {
        // Fix for AlpineJS (needs strict nonce AND unsafe-eval)
        $policy->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL);

        if ($requiresUnsafeInlineStyles) {
            // Fix for Filament Style Attributes (needs unsafe-inline WITHOUT nonce)
            $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
        } else {
            $policy->addNonce(Directive::STYLE);
        }
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
