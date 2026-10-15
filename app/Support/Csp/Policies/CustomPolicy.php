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
        if (request()->is(Filament::getPanel('admin')->getPath().'*') || $this->estUnePageDeHorizon()) {
            $policy->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL);
        }

        // 'unsafe-inline' ne suffit pas sur le seul style-src global :
        // style-src-attr est posé en plus pour autoriser les attributs de style
        // portés par les éléments. Les nonces de style restent désactivés,
        // Filament injectant ses balises <style> à l'exécution.
        $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
        $policy->add(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE);
    }

    /**
     * Horizon monte un composant Vue sans gabarit ni rendu : Vue compile alors
     * le contenu de `#horizon` à l'exécution, avec new Function, et sans
     * 'unsafe-eval' le tableau de bord restait vide en production (#1921).
     * Pulse l'a déjà, par sa propre politique. Le chemin exact ou ses sous-pages,
     * pas un voisin qui en partagerait le préfixe.
     */
    private function estUnePageDeHorizon(): bool
    {
        $chemin = trim(config()->string('horizon.path', 'horizon'), '/');

        return $chemin !== '' && request()->is($chemin, $chemin.'/*');
    }

    /**
     * Les seules sources d'une autre origine que la politique admet, les
     * services push des navigateurs, chacune avec sa raison dans
     * `LaCspNOuvreAucuneAutreOrigineTest`, qui refuse toute autre.
     *
     * Plus de site de polices : Horizon, Telescope et Pulse y prenaient la
     * fonte de leur tableau de bord, et leurs gabarits sont désormais
     * compilés sans ces balises (`RetireLesRessourcesDistantesDesPaquets`) ;
     * le panneau sert la sienne, et les fontes de l'application sont
     * auto-hébergées. Plus de service d'avatars : l'avatar par défaut du
     * panneau est dessiné par l'application (`AvatarDesInitiales`) et servi
     * en `data:`. Plus de site d'icônes : les logos de connexion sont
     * dessinés par l'application (#2051), et
     * `LesRessourcesViennentDeLApplicationTest` refuse que ce qu'elle sert
     * fasse charger quoi que ce soit d'une autre origine ; les gabarits des
     * paquets, eux, sont tenus par `RessourcesDistantesDesTableauxDeBordTest`.
     */
    protected function configureExternalResources(Policy $policy): void
    {
        $policy
            ->add(Directive::IMG, 'data:')
            ->add(Directive::FONT, 'data:')
            ->add(Directive::CONNECT, 'https://fcm.googleapis.com')
            ->add(Directive::CONNECT, 'https://updates.push.apple.com')
            ->add(Directive::CONNECT, 'https://*.notify.windows.com');
    }
}
