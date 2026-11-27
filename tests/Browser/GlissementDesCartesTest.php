<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * La rangée qu'on tire (SwipeableRow) se mesure dans le navigateur : sa
 * géométrie, ce que le verre de la carte laisse de son liseré, et la fluidité
 * du geste, image après image.
 */
class GlissementDesCartesTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function bibliothequeAuTelephone(Browser $browser): int
    {
        $user = User::factory()->create();
        $exercice = Exercise::factory()->create([
            'user_id' => $user->id,
            'name' => 'Développé couché',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $browser->loginAs($user)
            ->resizeToIphone15()
            ->visit('/exercises')
            ->disableAnimations()
            ->waitFor('#main-content', 30)
            ->waitFor("@exercise-card-{$exercice->id}", 15)
            ->waitUntil('document.fonts.status === "loaded"', 10);

        $browser->script("document.querySelector('[dusk=\"exercise-card-{$exercice->id}\"]').scrollIntoView({block: 'center', behavior: 'instant'});");

        return $exercice->id;
    }

    /**
     * Ouvre la rangée comme le clavier, puis attend que le ressort se soit posé :
     * la position du bord de la carte ne change plus d'une image à l'autre.
     */
    private function ouvrirAuFocus(Browser $browser, int $exercice): void
    {
        $browser->script(<<<JS
            window.__gliBase = document.querySelector('[dusk="exercise-card-{$exercice}"]').getBoundingClientRect().left;
            window.__gliAvant = null;
            document.querySelector('[dusk="swipe-edit-exercise-{$exercice}"]').focus();
            JS);

        $browser->waitUntil(<<<JS
            (() => {
                const gauche = Math.round(document.querySelector('[dusk="exercise-card-{$exercice}"]').getBoundingClientRect().left * 10);
                const stable = window.__gliAvant === gauche;
                window.__gliAvant = gauche;

                return stable && gauche / 10 - window.__gliBase > 70;
            })()
            JS, 10, 'la rangée ne s\'est pas posée ouverte');
    }

    public function test_le_panneau_a_la_forme_de_la_carte_et_la_suit_a_huit_pixels(): void
    {
        $this->browse(function (Browser $browser): void {
            try {
                $exercice = $this->bibliothequeAuTelephone($browser);
                $opaciteDeLIcone = "return getComputedStyle(document.querySelector('[dusk=\"swipe-edit-exercise-{$exercice}\"]').firstElementChild).opacity;";

                $this->assertSame('0', $browser->script($opaciteDeLIcone)[0], 'Fermée, l\'icône du panneau est effacée.');

                $this->ouvrirAuFocus($browser, $exercice);

                $this->assertSame('1', $browser->script($opaciteDeLIcone)[0], 'Ouverte, elle est pleine : le fondu s\'applique bien au contenu du bouton.');

                /** @var array{hauteurCarte: float, hauteurPanneau: float, haut: array{float, float}, ecartDesBoites: float, ecartAMiHauteur: float, ecartPresDuCoin: float, rayonCarte: list<string>, rayonPanneau: list<string>, rayonEnPixels: float} $mesure */
                $mesure = $browser->script(<<<JS
                    const carte = document.querySelector('[dusk="exercise-card-{$exercice}"]');
                    const panneau = document.querySelector('[data-panneau="gauche"]');
                    const c = carte.getBoundingClientRect();
                    const p = panneau.getBoundingClientRect();
                    const rayons = (el) => {
                        const s = getComputedStyle(el);
                        return [s.borderTopLeftRadius, s.borderTopRightRadius, s.borderBottomRightRadius, s.borderBottomLeftRadius];
                    };
                    const dans = (el, x, y) => {
                        const touche = document.elementFromPoint(x, y);
                        return touche !== null && el.contains(touche);
                    };
                    const ecartA = (y) => {
                        let bordDuPanneau = null;
                        let bordDeLaCarte = null;
                        for (let x = p.left; x < c.left + 40; x += 0.25) {
                            if (dans(panneau, x, y)) { bordDuPanneau = x; }
                            if (bordDeLaCarte === null && dans(carte, x, y)) { bordDeLaCarte = x; }
                        }
                        return bordDeLaCarte - bordDuPanneau;
                    };

                    return {
                        hauteurCarte: c.height,
                        hauteurPanneau: p.height,
                        haut: [c.top, p.top],
                        ecartDesBoites: c.left - p.right,
                        ecartAMiHauteur: ecartA(c.top + c.height / 2),
                        ecartPresDuCoin: ecartA(c.top + 3),
                        rayonCarte: rayons(carte),
                        rayonPanneau: rayons(panneau),
                        rayonEnPixels: parseFloat(getComputedStyle(carte).borderTopLeftRadius),
                    };
                    JS)[0];

                $this->assertEqualsWithDelta($mesure['hauteurCarte'], $mesure['hauteurPanneau'], 0.5, 'Le panneau a la hauteur de la carte.');
                $this->assertEqualsWithDelta($mesure['haut'][0], $mesure['haut'][1], 0.5, 'Le panneau part du haut de la carte.');
                $this->assertEqualsWithDelta(8.0, $mesure['ecartDesBoites'], 0.5, 'Huit pixels entre les boîtes.');
                $this->assertEqualsWithDelta(8.0, $mesure['ecartAMiHauteur'], 1.0, 'Huit pixels à mi-hauteur, mesurés sur ce qui se touche.');
                $this->assertGreaterThanOrEqual(8.0, $mesure['ecartPresDuCoin'] + 0.5, 'Près du coin, l\'écart ne se resserre jamais.');
                $this->assertLessThanOrEqual(8.0 + 2 * $mesure['rayonEnPixels'], $mesure['ecartPresDuCoin'], 'Près du coin, il ne s\'ouvre que de la courbure des deux rayons.');
                $this->assertSame($mesure['rayonCarte'], $mesure['rayonPanneau'], 'Le panneau a les rayons de la carte.');
            } finally {
                $browser->resize(393, 852);
            }
        });
    }

    public function test_le_verre_de_la_carte_laisse_son_lisere_de_categorie_et_son_ombre(): void
    {
        $this->browse(function (Browser $browser): void {
            try {
                $exercice = $this->bibliothequeAuTelephone($browser);

                /** @var array{largeur: string, couleur: string, couleurDuJeton: string, ombre: string, racineX: string, racineY: string, racineRayon: string} $mesure */
                $mesure = $browser->script(<<<JS
                    const carte = document.querySelector('[dusk="exercise-card-{$exercice}"]');
                    const style = getComputedStyle(carte);
                    const jeton = document.createElement('span');
                    jeton.style.color = 'var(--color-category-chest)';
                    document.body.appendChild(jeton);
                    const couleurDuJeton = getComputedStyle(jeton).color;
                    jeton.remove();
                    const racine = carte.closest('[data-glissant]').parentElement;

                    return {
                        largeur: style.borderLeftWidth,
                        couleur: style.borderLeftColor,
                        couleurDuJeton,
                        ombre: style.boxShadow,
                        racineX: getComputedStyle(racine).overflowX,
                        racineY: getComputedStyle(racine).overflowY,
                        racineRayon: getComputedStyle(racine).borderTopLeftRadius,
                    };
                    JS)[0];

                $this->assertSame('6px', $mesure['largeur']);
                $this->assertSame($mesure['couleurDuJeton'], $mesure['couleur']);
                $this->assertNotSame('none', $mesure['ombre']);
                $this->assertSame('visible', $mesure['racineY'], 'Rien ne rogne l\'ombre ni les coins par le haut ou le bas.');
                $this->assertSame('clip', $mesure['racineX'], 'La rangée ne déborde jamais sur le côté.');
                $this->assertSame('0px', $mesure['racineRayon']);
            } finally {
                $browser->resize(393, 852);
            }
        });
    }

    /**
     * Un doigt d'évènements de contact synthétiques (WebDriver ne sait pas
     * glisser) : il prouve la boucle d'images, pas le chemin tactile du système,
     * que seul le simulateur rejoue. Dusk force `prefers-reduced-motion` : pour mesurer le
     * ressort, la page répond « non » à la question, le temps du geste.
     *
     * @return array{doigt: list<array{doigt: float, rangee: float}>, lache: float, apres: list<float>, ouverte: ?string}
     */
    private function jouerUnGeste(Browser $browser, int $exercice, bool $mouvementReduit): array
    {
        $reduit = $mouvementReduit ? 'true' : 'false';

        /** @var array{doigt: list<array{doigt: float, rangee: float}>, lache: float, apres: list<float>, ouverte: ?string} $trace */
        $trace = $browser->driver->executeAsyncScript(<<<JS
            const fini = arguments[arguments.length - 1];
            if (!{$reduit}) {
                window.matchMedia = (media) => ({ matches: false, media, addEventListener() {}, removeEventListener() {} });
            }
            const contenu = document.querySelector('[dusk="exercise-card-{$exercice}"]').closest('[data-glissant]');
            const position = () => {
                const m = contenu.style.transform.match(/translateX\((-?[\d.]+)px\)/);
                return m ? parseFloat(m[1]) : 0;
            };
            const envoyer = (type, x) => {
                const contact = new Touch({ identifier: 7, target: contenu, clientX: x, clientY: 400 });
                const fin = type === 'touchend';
                contenu.dispatchEvent(new TouchEvent(type, {
                    touches: fin ? [] : [contact], changedTouches: [contact], bubbles: true, cancelable: true,
                }));
            };
            const image = () => new Promise((resolu) => requestAnimationFrame(resolu));

            (async () => {
                const doigt = [];
                const apres = [];
                let x = 300;
                envoyer('touchstart', x);
                for (let pas = 0; pas < 10; pas += 1) {
                    x -= 8;
                    envoyer('touchmove', x);
                    await image();
                    doigt.push({ doigt: x, rangee: position() });
                }
                envoyer('touchend', x);
                const lache = position();
                let stable = 0;
                let precedent = lache;
                for (let i = 0; i < 150 && stable < 8; i += 1) {
                    await image();
                    const courant = position();
                    apres.push(courant);
                    stable = Math.abs(courant - precedent) < 0.05 ? stable + 1 : 0;
                    precedent = courant;
                }
                fini({ doigt, lache, apres, ouverte: contenu.parentElement.dataset.ouverte ?? null });
            })();
            JS);

        return $trace;
    }

    public function test_la_rangee_suit_le_doigt_puis_se_pose_au_ressort_sans_saut(): void
    {
        $this->browse(function (Browser $browser): void {
            try {
                $exercice = $this->bibliothequeAuTelephone($browser);
                $trace = $this->jouerUnGeste($browser, $exercice, mouvementReduit: false);

                // Le premier point est sous l'hystérésis, le deuxième pose l'origine au verrou.
                $suivi = array_map(
                    fn (array $point): float => abs(($point['rangee'] - $trace['doigt'][1]['rangee']) - ($point['doigt'] - $trace['doigt'][1]['doigt'])),
                    array_slice($trace['doigt'], 2),
                );
                $this->assertLessThanOrEqual(1.0, max([0.0, ...$suivi]), 'Pendant le geste, la rangée suit le doigt à un pour un.');

                $apres = $trace['apres'];
                $pas = [];
                $precedent = $trace['lache'];

                foreach ($apres as $position) {
                    $pas[] = abs($position - $precedent);
                    $precedent = $position;
                }

                $this->assertSame('droite', $trace['ouverte'], 'Un geste de 80 px ouvre la rangée.');
                $this->assertGreaterThanOrEqual(8, count($apres), 'Le ressort se joue sur plusieurs images, il ne saute pas.');
                $this->assertLessThan(abs($trace['lache'] + 80), abs($apres[0] + 80), 'La première image se rapproche déjà du cran.');
                $this->assertGreaterThan(1.0, abs($apres[0] + 80), 'La première image n\'est pas déjà sur le cran : le ressort ne saute pas.');
                $this->assertGreaterThanOrEqual(6, count(array_filter($apres, fn (float $position): bool => abs($position + 80) > 0.5)), 'Au moins six images entre le lâcher et la pose.');
                $this->assertLessThanOrEqual(30.0, max([0.0, ...$pas]), 'Aucune image ne saute de plus de 30 px.');
                $this->assertEqualsWithDelta(-80.0, end($apres), 0.5, 'La rangée se pose sur le cran.');
                $this->assertGreaterThanOrEqual(-80.0 - 12.0, min([0.0, ...$apres]), 'Le dépassement reste sous 12 px.');

                if (is_string(getenv('GLISSEMENT_TRACE')) && getenv('GLISSEMENT_TRACE') !== '') {
                    fwrite(STDERR, json_encode(['lache' => $trace['lache'], 'images' => count($apres), 'plusGrandPas' => max([0.0, ...$pas]), 'minimum' => min([0.0, ...$apres]), 'apres' => $apres], JSON_THROW_ON_ERROR)."\n");
                }
            } finally {
                $browser->resize(393, 852);
            }
        });
    }

    public function test_en_mouvement_reduit_la_rangee_suit_le_doigt_et_se_pose_sans_image_intermediaire(): void
    {
        $this->browse(function (Browser $browser): void {
            try {
                $exercice = $this->bibliothequeAuTelephone($browser);
                $trace = $this->jouerUnGeste($browser, $exercice, mouvementReduit: true);

                $this->assertTrue(
                    (bool) $browser->script('return matchMedia("(prefers-reduced-motion: reduce)").matches;')[0],
                    'Dusk force le mouvement réduit : c\'est la branche mesurée ici.',
                );
                $this->assertSame('droite', $trace['ouverte']);
                $this->assertEqualsWithDelta(-80.0, $trace['apres'][0], 0.001, 'Posée sur le cran dès l\'image qui suit le relâchement.');
                $this->assertEqualsWithDelta(-80.0, end($trace['apres']), 0.001);
            } finally {
                $browser->resize(393, 852);
            }
        });
    }
}
