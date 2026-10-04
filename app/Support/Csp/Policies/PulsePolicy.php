<?php

declare(strict_types=1);

namespace App\Support\Csp\Policies;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class PulsePolicy extends Basic
{
    /**
     * Les balises `<script>` et `<style>` de Pulse portent le nonce de la
     * requête ; ses attributs `style`, eux, ne peuvent pas le porter.
     *
     * `style-src-attr 'unsafe-inline'`, comme sur le panneau (`CustomPolicy`) :
     * sans lui, le navigateur refusait le `style="display: none;"` du menu de
     * thème de Pulse, qui s'affichait alors jusqu'au démarrage d'Alpine. Il ne
     * vaut que pour les attributs : les balises `<style>` restent sous le nonce.
     */
    #[\Override]
    public function configure(Policy $policy): void
    {
        parent::configure($policy);

        $policy
            ->add(Directive::SCRIPT, Keyword::UNSAFE_EVAL)
            ->addNonce(Directive::SCRIPT)
            ->addNonce(Directive::STYLE)
            ->add(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->add(Directive::STYLE, 'https://fonts.bunny.net')
            ->add(Directive::FONT, [Keyword::SELF, 'https://fonts.bunny.net'])
            ->add(Directive::IMG, [Keyword::SELF, 'data:', 'https:']);
    }
}
