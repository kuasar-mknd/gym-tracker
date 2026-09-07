<?php

declare(strict_types=1);

namespace App\Support\Csp\Policies;

use Filament\Facades\Filament;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class CustomPolicy extends Basic
{
    #[\Override]
    public function configure(Policy $policy): void
    {
        // parent::configure() n'est pas appelé : le préréglage Basic pose un
        // nonce sur STYLE, ce qui annule complètement 'unsafe-inline' pour les
        // attributs de style, dont Filament a besoin.

        // Le préréglage Basic est donc rejoué ici, sans le nonce de style.
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
            ->add(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->add(Directive::STYLE, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'http://localhost:5173')
            ->add(Directive::CONNECT, 'ws://localhost:5173');
    }

    protected function configureProduction(Policy $policy): void
    {
        // Alpine, que Filament embarque, compile ses expressions avec new Function :
        // il lui faut 'unsafe-eval'. L'application Vue n'en a pas besoin, donc le
        // mot-clef ne sort que sur le panneau.
        if (request()->is(Filament::getPanel('admin')->getPath().'*')) {
            $policy->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL);
        }

        // 'unsafe-inline' ne suffit pas sur le seul style-src global :
        // style-src-attr est posé en plus pour autoriser les attributs de style
        // portés par les éléments. Les nonces de style restent désactivés,
        // Filament injectant ses balises <style> à l'exécution.
        $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
        $policy->add(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE);
    }

    protected function configureExternalResources(Policy $policy): void
    {
        /**
         * fonts.googleapis.com et fonts.gstatic.com sont partis avec les fontes
         * elles-mêmes — elles sont auto-hébergées désormais, donc l'application
         * n'a plus de raison de laisser une page joindre Google pour une feuille
         * de style ou une police.
         *
         * fonts.bunny.net reste : Horizon, Telescope, Pulse et Filament y
         * prennent tous la fonte de leur tableau de bord, et aucun d'eux n'est à
         * nous pour qu'on la ré-héberge.
         */
        $policy
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::IMG, 'data:')
            ->add(Directive::IMG, 'https://ui-avatars.com')
            ->add(Directive::IMG, 'https://www.svgrepo.com')
            ->add(Directive::FONT, 'https://fonts.bunny.net')
            ->add(Directive::FONT, 'data:')
            ->add(Directive::CONNECT, 'https://fcm.googleapis.com')
            ->add(Directive::CONNECT, 'https://updates.push.apple.com')
            ->add(Directive::CONNECT, 'https://*.notify.windows.com');
    }
}
