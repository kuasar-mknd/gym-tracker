<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\WebDriverTargetLocator;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;

/**
 * Ce que les parcours hors ligne partagent (#2048, parties E et F).
 *
 * Les copies vivent dans IndexedDB (`gym-tracker-copies`), que seule la page
 * ouvre : un parcours les lit par script, comme un autre programme de
 * l'appareil les lirait, et déchiffre un enregistrement par la clé qu'il lui
 * donne, avec les données authentifiées du magasin (`compte|chemin|contrat`).
 *
 * **La coupure réelle.** `Network.emulateNetworkConditions`, que
 * `ChromeDevToolsDriver` envoie, ne coupe que la page : la requête préchargée
 * d'une navigation y échoue comme sans réseau, mais un `fetch` ou un `import()`
 * que le worker relaie atteint encore le serveur (`.ai/rules/browser.md`).
 * `sansReseauNiConstruction()` coupe donc le réseau de la page ET rend
 * public/build introuvable (renommé, rétabli dans son `finally`), après avoir
 * vidé le cache HTTP du navigateur et les deux caches du worker que la route
 * des actifs lit après le precache : ce qui s'ouvre pendant la coupure vient du
 * precache et des copies, ou ne s'ouvre pas. Elle attend d'abord que la page
 * n'ait plus de visite d'Inertia en vol, et une page ouverte sans réseau se
 * vérifie à l'adresse que sa navigation demandait (#2091,
 * `attendreLaCoquilleServieA()`). Sous une coupure, celle-ci ou celle que le
 * parcours pose par `reglerLeReseau()`, une visite qui quitte la coquille
 * laissée telle quelle part de la page blanche d'un onglet neuf, l'onglet
 * quitté fermé (`partirDUnePageBlanche()`) : quittée dans son onglet, la
 * coquille se recharge au départ, et sous Chrome 155 et 156 ce rechargement
 * prenait la place de la page demandée à chaque passe.
 *
 * **La lenteur.** `reglerLeReseau()` pose aussi une latence : Chrome la compte
 * de l'envoi de la requête à l'arrivée de ses en-têtes, sur chaque requête de
 * la page, la requête préchargée d'une navigation comprise (`ReseauLentTest`
 * le vérifie : sans elle, le serveur local répondrait avant le délai du
 * worker). Le worker, lui, sert ses caches sans latence.
 *
 * Les textes se lisent dans le DOM (`texteDe()`) : `titre-carte` et
 * `glass-button` passent en majuscules, et WebDriver rend le texte transformé.
 */
trait ParcoursHorsLigne
{
    /**
     * Le magasin lu par script : chaque copie (chemin, composant, date de la
     * dernière réponse complète, empreinte de sa clé), rien de chiffré, et
     * l'état. Une base absente ne se crée pas ici.
     */
    private const string LIRE_LE_MAGASIN_DES_COPIES = <<<'SCRIPT'
        const fini = arguments[arguments.length - 1];
        const ouverture = indexedDB.open('gym-tracker-copies');
        ouverture.onupgradeneeded = () => ouverture.transaction.abort();
        ouverture.onerror = () => fini({ base: false, copies: [], etat: null });
        ouverture.onsuccess = () => {
            const base = ouverture.result;
            const transaction = base.transaction(['pages', 'etat'], 'readonly');
            const pages = transaction.objectStore('pages').getAll();
            const etat = transaction.objectStore('etat').get('etat');
            transaction.oncomplete = () => {
                base.close();
                fini({
                    base: true,
                    copies: pages.result.map((copie) => ({
                        chemin: copie.chemin,
                        composant: copie.composant,
                        copieDu: copie.copieDu,
                        empreinte: copie.empreinte,
                    })),
                    etat: etat.result ?? null,
                });
            };
            transaction.onerror = () => {
                base.close();
                fini({ base: true, copies: [], etat: null });
            };
        };
    SCRIPT;

    /**
     * Essaie la clé donnée (base64url, comme le témoin la porte) sur chaque
     * enregistrement du magasin, avec les données authentifiées du compte
     * donné : rend, pour chacun, son chemin et le texte déchiffré, ou `null`
     * quand la clé ne l'ouvre pas.
     */
    private const string DECHIFFRER_LE_MAGASIN = <<<'SCRIPT'
        const [cleEnBase64, compte, contrat, fini] = arguments;
        (async () => {
            const binaire = atob(cleEnBase64.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(cleEnBase64.length / 4) * 4, '='));
            const cle = await crypto.subtle.importKey(
                'raw',
                Uint8Array.from(binaire, (lettre) => lettre.charCodeAt(0)),
                { name: 'AES-GCM' },
                false,
                ['decrypt'],
            );
            const base = await new Promise((ouverte, refusee) => {
                const demande = indexedDB.open('gym-tracker-copies');
                demande.onupgradeneeded = () => demande.transaction.abort();
                demande.onsuccess = () => ouverte(demande.result);
                demande.onerror = () => refusee(demande.error);
            }).catch(() => null);
            if (base === null) {
                return [];
            }
            const copies = await new Promise((lues, refusee) => {
                const demande = base.transaction('pages').objectStore('pages').getAll();
                demande.onsuccess = () => lues(demande.result);
                demande.onerror = () => refusee(demande.error);
            });
            base.close();

            return Promise.all(
                copies.map(async (copie) => {
                    try {
                        const clair = await crypto.subtle.decrypt(
                            {
                                name: 'AES-GCM',
                                iv: copie.iv,
                                additionalData: new TextEncoder().encode(`${compte}|${copie.chemin}|${contrat}`),
                            },
                            cle,
                            copie.chiffre,
                        );

                        return { chemin: copie.chemin, texte: new TextDecoder().decode(clair) };
                    } catch {
                        return { chemin: copie.chemin, texte: null };
                    }
                }),
            );
        })().then(fini, (erreur) => fini({ erreur: String(erreur) }));
    SCRIPT;

    /**
     * Vide les caches du worker que la route des actifs lit après le precache :
     * il ne reste au navigateur que ce que le worker a précaché à l'installation.
     */
    private const string VIDER_LES_CACHES_DES_ACTIFS = <<<'SCRIPT'
        const fini = arguments[arguments.length - 1];
        Promise.all(['actifs-a-la-demande', 'construction-precedente'].map((nom) => caches.delete(nom))).then(
            () => fini(true),
            (erreur) => fini(String(erreur)),
        );
    SCRIPT;

    /**
     * Le nombre de requêtes que le routeur d'Inertia a en vol, dans ses deux
     * files : `null` sans routeur, `-1` quand ses files n'ont plus la forme
     * que ce script lit.
     */
    private const string VISITES_D_INERTIA_EN_VOL = <<<'SCRIPT'
        const routeur = window.Inertia;
        if (routeur === undefined || routeur === null) {
            return null;
        }
        const files = [routeur.syncRequestStream?.requests, routeur.asyncRequestStream?.requests];

        return files.every(Array.isArray) ? files[0].length + files[1].length : -1;
    SCRIPT;

    /**
     * Le document affiché, en JSON : son adresse, celle que demandait la
     * navigation qui l'a ouvert et son type, et la marque de la coquille.
     */
    private const string CE_QU_EST_LE_DOCUMENT = <<<'SCRIPT'
        const navigation = performance.getEntriesByType('navigation')[0];

        return JSON.stringify({
            adresse: location.pathname,
            navigation: navigation ? { adresse: new URL(navigation.name).pathname, type: navigation.type } : null,
            coquille: document.documentElement.hasAttribute('data-coquille'),
            raison: document.documentElement.getAttribute('data-raison'),
        });
    SCRIPT;

    /**
     * Note chaque message que l'application annonce faute de réseau : les
     * toasts des layouts les montrent (`gym:sans-reseau`), puis s'effacent
     * d'eux-mêmes au bout de huit secondes.
     */
    private const string NOTER_LES_MESSAGES = <<<'SCRIPT'
        window.messagesSansReseau = [];
        window.addEventListener('gym:sans-reseau', (evenement) => {
            if (typeof evenement.detail?.message === 'string') {
                window.messagesSansReseau.push(evenement.detail.message);
            }
        });
    SCRIPT;

    /**
     * Le chiffrement exige un contexte sûr : la CI sert 127.0.0.1 ; sous Sail,
     * servi en http sur `laravel.test`, ni worker ni historique chiffré, et le
     * parcours se déclare sauté.
     */
    private function exigerUnContexteSur(Browser $browser): void
    {
        if ($browser->script("return window.isSecureContext === true && 'serviceWorker' in navigator && 'indexedDB' in window && !!window.crypto?.subtle;")[0] !== true) {
            Assert::markTestSkipped('Origine en http hors boucle locale : ni worker ni historique chiffré, et rien n’est gardé.');
        }
    }

    /**
     * Attend le worker installé, precache compris, et maître de la page.
     */
    private function attendreLeWorker(Browser $browser): void
    {
        $browser->waitUntil(
            "navigator.serviceWorker.controller !== null && navigator.serviceWorker.controller.state === 'activated'",
            30,
            'le service worker n’a jamais pris la page en charge',
        );
    }

    /**
     * @return array{base: bool, copies: list<array{chemin: string, composant: string, copieDu: int|float, empreinte: string}>, etat: array{ouvert: bool, compte: string|null, empreinte: string|null, contrat: int, empreintesFermees: list<string>}|null}
     */
    private function lireLeMagasin(Browser $browser): array
    {
        /** @var array{base: bool, copies: list<array{chemin: string, composant: string, copieDu: int|float, empreinte: string}>, etat: array{ouvert: bool, compte: string|null, empreinte: string|null, contrat: int, empreintesFermees: list<string>}|null} $magasin */
        $magasin = $browser->driver->executeAsyncScript(self::LIRE_LE_MAGASIN_DES_COPIES);

        return $magasin;
    }

    /**
     * Attend que le magasin garde une copie de chaque chemin : elles s'écrivent
     * au repos, après la page qu'elles gardent, et le préchauffage les demande
     * l'une après l'autre.
     *
     * @param  list<string>  $chemins
     * @return array{base: bool, copies: list<array{chemin: string, composant: string, copieDu: int|float, empreinte: string}>, etat: array{ouvert: bool, compte: string|null, empreinte: string|null, contrat: int, empreintesFermees: list<string>}|null}
     */
    private function attendreLesCopies(Browser $browser, array $chemins, int $secondes = 30): array
    {
        $limite = microtime(true) + $secondes;

        do {
            $magasin = $this->lireLeMagasin($browser);
            $manquants = array_values(array_diff($chemins, array_column($magasin['copies'], 'chemin')));

            if ($manquants === []) {
                return $magasin;
            }

            usleep(250_000);
        } while (microtime(true) < $limite);

        Assert::fail('le magasin n’a pas gardé : '.implode(', ', $manquants));
    }

    /**
     * Attend que le magasin ne garde plus aucune copie : une purge vide la
     * base dans sa transaction, mais part après la page qui la déclenche.
     *
     * @return array{base: bool, copies: list<array{chemin: string, composant: string, copieDu: int|float, empreinte: string}>, etat: array{ouvert: bool, compte: string|null, empreinte: string|null, contrat: int, empreintesFermees: list<string>}|null}
     */
    private function attendreUnMagasinVide(Browser $browser, string $message, int $secondes = 15): array
    {
        $limite = microtime(true) + $secondes;

        do {
            $magasin = $this->lireLeMagasin($browser);

            if ($magasin['copies'] === []) {
                return $magasin;
            }

            usleep(250_000);
        } while (microtime(true) < $limite);

        Assert::fail($message.' : '.implode(', ', array_column($magasin['copies'], 'chemin')));
    }

    /**
     * Essaie une clé sur chaque copie du magasin, sous le compte donné.
     *
     * @return list<array{chemin: string, texte: string|null}>
     */
    private function dechiffrerLeMagasin(Browser $browser, string $cle, string $compte): array
    {
        /** @var list<array{chemin: string, texte: string|null}>|array{erreur: string} $copies */
        $copies = $browser->driver->executeAsyncScript(
            self::DECHIFFRER_LE_MAGASIN,
            [$cle, $compte, PagesGardees::versionDuContrat()],
        );

        Assert::assertArrayNotHasKey('erreur', $copies, 'le magasin ne s’est pas lu');

        /** @var list<array{chemin: string, texte: string|null}> $copies */
        return $copies;
    }

    /**
     * La clé que porte le témoin des copies, ou `null` sans lui.
     */
    private function temoinDesCopies(Browser $browser): ?string
    {
        $cle = $browser->script(<<<'SCRIPT'
            const temoin = document.cookie
                .split('; ')
                .map((morceau) => morceau.split('='))
                .find(([nom]) => nom === '__Host-gt_copies' || nom === 'gt_copies');

            return temoin ? temoin[1] : null;
        SCRIPT)[0];

        return is_string($cle) ? $cle : null;
    }

    /**
     * Les outils de Chrome de la page, réseau suivi.
     */
    private function outilsDuNavigateur(Browser $browser): ChromeDevToolsDriver
    {
        $outils = new ChromeDevToolsDriver($browser->driver);
        $outils->execute('Network.enable');

        return $outils;
    }

    /**
     * Coupe ou rend le réseau de la page, avec une latence en millisecondes
     * sur chaque requête qui part.
     */
    private function reglerLeReseau(ChromeDevToolsDriver $outils, bool $coupe, int $latence = 0): void
    {
        $outils->execute('Network.emulateNetworkConditions', [
            'offline' => $coupe,
            'latency' => $latence,
            'downloadThroughput' => -1,
            'uploadThroughput' => -1,
        ]);
    }

    /**
     * Joue `$parcours` réseau de la page coupé et public/build introuvable,
     * puis rétablit l'une et l'autre, quoi qu'il arrive : la construction
     * d'abord, le réseau ensuite. Une construction retirée restée d'une passe
     * interrompue fait échouer le parcours avant toute coupure.
     *
     * Rien ne se coupe tant que la page attend une visite d'Inertia
     * (`attendreAucuneVisiteEnVol()`, #2091) : la coupure n'arrête pas une
     * requête déjà partie, dont la réponse arrive encore, et le serveur, qui
     * ne voit plus public/build, y répond 409 sans version d'actifs. Inertia
     * en fait une visite complète de l'adresse de la page, qui l'emporte sur
     * celle que le parcours lance.
     *
     * @param  Closure(): void  $parcours
     */
    private function sansReseauNiConstruction(Browser $browser, ChromeDevToolsDriver $outils, Closure $parcours): void
    {
        $this->attendreAucuneVisiteEnVol($browser);

        Assert::assertTrue(
            $browser->driver->executeAsyncScript(self::VIDER_LES_CACHES_DES_ACTIFS),
            'les caches des actifs du worker ne se sont pas vidés',
        );
        $outils->execute('Network.clearBrowserCache');

        $construction = public_path('build');
        $constructionRetiree = $construction.'.retiree';
        Assert::assertDirectoryExists($construction, 'la construction doit exister');
        Assert::assertDirectoryDoesNotExist($constructionRetiree, 'une construction retirée d’une passe précédente reste');

        $retiree = false;

        try {
            $this->reglerLeReseau($outils, true);
            $retiree = rename($construction, $constructionRetiree);
            Assert::assertTrue($retiree, 'la construction ne s’est pas retirée');

            $parcours();
        } finally {
            if ($retiree) {
                rename($constructionRetiree, $construction);
            }

            $this->reglerLeReseau($outils, false);
        }
    }

    /**
     * Note, à partir de maintenant, les messages annoncés faute de réseau.
     */
    private function noterLesMessages(Browser $browser): void
    {
        $browser->script(self::NOTER_LES_MESSAGES);
    }

    /**
     * Les messages annoncés faute de réseau depuis `noterLesMessages()`, dans
     * l'ordre.
     *
     * @return list<string>
     */
    private function messagesSansReseau(Browser $browser): array
    {
        /** @var list<string> $messages */
        $messages = $browser->script('return window.messagesSansReseau ?? [];')[0];

        return $messages;
    }

    /**
     * Le texte d'un élément, tel que le DOM le porte.
     */
    private function texteDe(string $selecteur): string
    {
        return 'document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).').textContent.trim()';
    }

    /**
     * Si la page porte ce texte quelque part.
     */
    private function laPageDit(string $texte): string
    {
        return 'document.body.textContent.includes('.json_encode($texte, JSON_THROW_ON_ERROR).')';
    }

    /**
     * Si la page est la coquille que le worker a servie, et pour quelle raison.
     * Avec un chemin, il faut aussi que la navigation qui a ouvert le document
     * l'ait demandé (#2091) : son entrée de `performance` garde l'adresse
     * demandée, qu'Inertia ne réécrit pas, quand l'adresse de la page devient
     * celle de la page montée.
     */
    private function laCoquilleServiePour(string $raison, ?string $chemin = null): string
    {
        $coquille = "document.documentElement.hasAttribute('data-coquille') && document.documentElement.getAttribute('data-raison') === ".json_encode($raison, JSON_THROW_ON_ERROR);

        if ($chemin === null) {
            return $coquille;
        }

        return "{$coquille} && new URL(performance.getEntriesByType('navigation')[0]?.name ?? 'about:blank').pathname === ".json_encode($chemin, JSON_THROW_ON_ERROR);
    }

    /**
     * Attend la coquille servie pour cette raison en réponse à la navigation
     * vers ce chemin (#2091). Une coquille servie à une autre adresse, comme
     * l'accueil que la page quittée recharge, ne passe pas : l'échec dit ce
     * qu'est le document à la place.
     */
    private function attendreLaCoquilleServieA(Browser $browser, string $chemin, string $raison = 'reseau', int $secondes = 15): Browser
    {
        try {
            return $browser->waitUntil($this->laCoquilleServiePour($raison, $chemin), $secondes);
        } catch (TimeoutException) {
            Assert::fail("la coquille n’a pas été servie à {$chemin} pour la raison {$raison} : ".$this->ceQuEstLeDocument($browser));
        }
    }

    /**
     * Quitte le document affiché pour la page blanche d'un onglet neuf, avant
     * une nouvelle visite sous une coupure, celle de
     * `sansReseauNiConstruction()` ou celle que le parcours pose par
     * `reglerLeReseau()` (#2091) : l'onglet quitté est fermé, et l'onglet neuf,
     * au premier plan, a le réseau coupé, sans latence.
     *
     * La coquille laissée telle quelle (sans copie à monter) recharge son
     * adresse au premier `online` (`laisserLaCoquille()`,
     * `resources/js/Utils/demarrageHorsLigne.js`), et Chrome en émet un dans
     * le document que le pilote fait naviguer sous
     * `Network.emulateNetworkConditions`, juste avant son `pagehide` : les
     * réglages des outils de Chrome quittent alors ce document, son réseau
     * émulé avec eux. Dans le même onglet, ce rechargement prenait la place de
     * la page que le pilote demandait ensuite. Sous Chromium 141, celle d'une
     * visite selon le parcours, rarement celle d'une page blanche ; sous
     * Chrome 155 et 156, ceux des images de la CI au 22/10/2026 (la CI prend
     * le Chrome de l'image ubuntu-latest, sans l'épingler), celle de presque
     * chaque visite, et celle de la page blanche au premier départ de la
     * coquille à chaque passe, et le plus souvent aux suivants, chaque
     * coquille rechargée reposant son écouteur : redemander la page blanche
     * ne gagnait plus. Fermé, l'onglet emporte le document, ses écouteurs, ses
     * minuteries et le rechargement qu'il lance ; rien n'en atteint l'onglet
     * neuf. Couper les scripts de la page le temps du départ
     * (`Emulation.setScriptExecutionDisabled`) n'y suffit pas : la coupure
     * quitte le document au même moment que le réseau émulé, et son écouteur
     * `online` est appelé quand même (`.ai/rules/browser.md`).
     *
     * L'onglet neuf garde le profil et la fenêtre : cookies, localStorage,
     * taille, agent et préférences, et ce que l'origine partage entre ses
     * onglets, le magasin des copies (IndexedDB) et les caches du worker, qui
     * sert la coquille. Il n'a ni le sessionStorage de l'onglet fermé, ni ses
     * réglages des outils de Chrome, ni le premier plan : `Page.bringToFront`
     * le lui donne (`document.hasFocus()`), et le réseau s'y coupe de nouveau
     * (`navigator.onLine`). Les outils de Chrome visent l'onglet courant du
     * pilote : ceux que le parcours a créés avant le départ rendent le réseau
     * à l'onglet neuf, dans le `finally` de `sansReseauNiConstruction()` comme
     * dans celui du parcours. La console du navigateur, que `DuskTestCase`
     * relève, garde les messages des deux onglets.
     */
    private function partirDUnePageBlanche(Browser $browser): Browser
    {
        $pilote = $browser->driver;
        $quitte = $pilote->getWindowHandle();
        $neuf = $pilote->switchTo()->newWindow(WebDriverTargetLocator::WINDOW_TYPE_TAB)->getWindowHandle();

        $pilote->switchTo()->window($quitte)->close();
        $pilote->switchTo()->window($neuf);

        Assert::assertSame([$neuf], $pilote->getWindowHandles(), 'l’onglet quitté ne s’est pas fermé');

        $outils = $this->outilsDuNavigateur($browser);
        $outils->execute('Page.bringToFront');
        $this->reglerLeReseau($outils, true);

        Assert::assertSame(
            ['about:blank', false, true],
            $browser->script('return [location.href, navigator.onLine, document.hasFocus()];')[0],
            'l’onglet neuf n’est pas une page blanche au premier plan, réseau coupé (adresse, navigator.onLine, document.hasFocus())',
        );

        return $browser;
    }

    /**
     * Ce qu'est le document affiché : son adresse, celle que la navigation
     * qui l'a ouvert demandait et son type (`navigate`, `reload`…), et s'il
     * est la coquille, et pour quelle raison. Ce que cite un échec.
     */
    private function ceQuEstLeDocument(Browser $browser): string
    {
        $document = $browser->script(self::CE_QU_EST_LE_DOCUMENT)[0];

        return is_string($document) ? $document : 'illisible';
    }

    /**
     * Attend que la page n'ait plus de visite d'Inertia en vol (#2091) : ni
     * visite, ni rechargement partiel, props différées comprises. Ce que
     * `sansReseauNiConstruction()` fait avant de couper.
     *
     * Le routeur est celui que `resources/js/main.js` expose aux parcours
     * (`window.Inertia`) : ses deux files gardent chacune les requêtes parties
     * et pas encore traitées. Un document sans routeur (la coquille laissée
     * telle quelle) n'a rien en vol. Un routeur sans ces deux listes, qu'une
     * montée d'Inertia aurait renommées, fait échouer l'attente au lieu de la
     * laisser passer à vide.
     */
    private function attendreAucuneVisiteEnVol(Browser $browser, int $secondes = 15): void
    {
        $limite = microtime(true) + $secondes;

        do {
            $compte = $browser->script(self::VISITES_D_INERTIA_EN_VOL)[0];
            $enVol = $compte === null ? 0 : (is_int($compte) ? $compte : -1);

            Assert::assertNotSame(-1, $enVol, 'le routeur d’Inertia n’a plus les files de requêtes que la coupure lit');

            if ($enVol === 0) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $limite);

        Assert::fail("la page a encore {$enVol} visite(s) d’Inertia en vol avant la coupure");
    }
}
