<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Compare l'image qu'exécutent app, worker et scheduler, d'après leurs
 * annonces dans le cache (#1813) : rouge quand l'un d'eux est resté sur une
 * autre image que la dernière déployée plus de dix minutes après son
 * déploiement, orange pendant ces dix minutes ou quand l'un d'eux ne s'est
 * jamais annoncé. La dernière image déployée est la plus haute version
 * publiée, puis, entre deux constructions d'une même version, celle dont la
 * première annonce est la plus récente : un conteneur en retard s'annonce
 * peut-être chaque minute, mais toujours depuis la même date, sauf quand le
 * cache a évincé son annonce (#1930). Production seulement : sous Sail, app
 * ne passe pas par Octane, et aucun conteneur n'exécute une image de la CI.
 */
final class VersionsDesConteneursCheck extends Check
{
    /**
     * Pendant une mise à jour de la pile, les conteneurs redémarrent l'un
     * après l'autre : un écart plus jeune que cela est une mise à jour en
     * cours, pas une panne, et ne doit pas écrire de courriel.
     */
    private const int MINUTES_DE_MISE_A_JOUR = 10;

    private const string QUOI_FAIRE = "Un conteneur garde l'image avec laquelle il a été créé : mettre à jour la pile en retéléchargeant l'image, puis vérifier que les trois conteneurs ont été recréés.";

    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Versions des conteneurs';

    #[\Override]
    public function getName(): string
    {
        return 'VersionsDesConteneurs';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production : ailleurs, aucun conteneur n\'exécute une image construite par la CI, et app, servi sans Octane, ne s\'annonce pas à son démarrage.');
        }

        $annonces = $this->annonces();
        $presentes = array_filter($annonces);
        $absents = array_keys(array_diff_key($annonces, $presentes));
        $reference = $this->derniereImageDeployee($presentes);
        $enRetard = $reference === null ? [] : array_keys(array_filter(
            $presentes,
            fn (array $annonce): bool => $this->identite($annonce) !== $this->identite($reference),
        ));

        $result = Result::make()
            ->meta(['annonces' => $presentes, 'en_retard' => $enRetard, 'absents' => $absents])
            ->shortSummary($this->resume($annonces, $reference, $enRetard, $absents));

        $messages = array_filter([
            $this->messageDEcart($presentes, $reference, $enRetard),
            $this->messageDAbsents($absents),
        ], is_string(...));

        if ($messages === []) {
            return $result->ok();
        }

        return $this->juger($result, implode(' ', $messages).' '.self::QUOI_FAIRE, $reference, $enRetard);
    }

    /**
     * Lues à chaque passage : une annonce lue au démarrage du processus
     * resterait celle d'avant la mise à jour.
     *
     * @return array<string, array{version: string, revision: string, depuis: string, le: string}|null>
     */
    private function annonces(): array
    {
        return array_combine(AnnonceDeVersion::CONTENEURS, array_map(AnnonceDeVersion::lue(...), AnnonceDeVersion::CONTENEURS));
    }

    /**
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @param  array{version: string, revision: string, depuis: string, le: string}|null  $reference
     * @param  list<string>  $enRetard
     */
    private function messageDEcart(array $presentes, ?array $reference, array $enRetard): ?string
    {
        if ($reference === null || $enRetard === []) {
            return null;
        }

        return $this->ecart($presentes, $reference, $enRetard);
    }

    /**
     * @param  list<string>  $absents
     */
    private function messageDAbsents(array $absents): ?string
    {
        if ($absents === []) {
            return null;
        }

        return sprintf(
            '%s ne %s jamais annoncé%s : arrêté, ou sur une image antérieure à ce contrôle.',
            $this->enumerer($absents),
            count($absents) > 1 ? 'se sont' : 's\'est',
            count($absents) > 1 ? 's' : '',
        );
    }

    /**
     * @param  array{version: string, revision: string, depuis: string, le: string}|null  $reference
     * @param  list<string>  $enRetard
     */
    private function juger(Result $result, string $message, ?array $reference, array $enRetard): Result
    {
        if ($enRetard === []) {
            return $result->warning($message);
        }

        if ($reference !== null && ! $this->miseAJourEnCours($reference)) {
            return $result->failed($message);
        }

        return $result->warning('Peut-être une mise à jour en cours : '.$message);
    }

    /**
     * L'annonce de la dernière image déployée, datée de sa première annonce,
     * tous conteneurs confondus : un conteneur à jour dont l'annonce revient
     * après une éviction ne rouvre pas les dix minutes de la mise à jour.
     *
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @return array{version: string, revision: string, depuis: string, le: string}|null
     */
    private function derniereImageDeployee(array $presentes): ?array
    {
        $reference = $this->laPlusRecente($presentes);

        if ($reference === null) {
            return null;
        }

        $reference['depuis'] = $this->premiereAnnonceDeLImage($presentes, $reference);

        return $reference;
    }

    /**
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @return array{version: string, revision: string, depuis: string, le: string}|null
     */
    private function laPlusRecente(array $presentes): ?array
    {
        $reference = null;

        foreach ($presentes as $annonce) {
            if ($reference === null || $this->estPlusRecente($annonce, $reference)) {
                $reference = $annonce;
            }
        }

        return $reference;
    }

    /**
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @param  array{version: string, revision: string, depuis: string, le: string}  $reference
     */
    private function premiereAnnonceDeLImage(array $presentes, array $reference): string
    {
        $depuis = $reference['depuis'];

        foreach ($presentes as $memeImage) {
            if ($this->identite($memeImage) === $this->identite($reference) && Carbon::parse($memeImage['depuis'])->lessThan(Carbon::parse($depuis))) {
                $depuis = $memeImage['depuis'];
            }
        }

        return $depuis;
    }

    /**
     * Entre deux versions publiées, la plus haute : une annonce évincée
     * revient datée de son retour, et un conteneur resté sur une ancienne
     * image passerait sinon pour le dernier mis à jour. Entre deux
     * constructions de `main`, qui portent la même version, ou hors d'une
     * version publiée, celle que son conteneur exécute depuis le moins
     * longtemps. Un retour en arrière vers une version plus ancienne
     * ressemble, dans le cache, à cette annonce revenue : il passe au rouge
     * sans attendre les dix minutes tant qu'un conteneur exécute encore la
     * plus haute version, et désigne ceux qui sont déjà revenus en arrière.
     *
     * @param  array{version: string, revision: string, depuis: string, le: string}  $annonce
     * @param  array{version: string, revision: string, depuis: string, le: string}  $reference
     */
    private function estPlusRecente(array $annonce, array $reference): bool
    {
        if ($annonce['version'] !== $reference['version'] && $this->estPubliee($annonce['version']) && $this->estPubliee($reference['version'])) {
            return version_compare(ltrim($annonce['version'], 'v'), ltrim($reference['version'], 'v'), '>');
        }

        return Carbon::parse($annonce['depuis'])->greaterThan(Carbon::parse($reference['depuis']));
    }

    /**
     * Une version publiée par un tag (`v1.5.20`), que l'on sait ordonner ;
     * ni `main`, ni `dev`.
     */
    private function estPubliee(string $version): bool
    {
        return preg_match('/^v?\d+(\.\d+)+$/', $version) === 1;
    }

    /**
     * @param  array{version: string, revision: string, depuis: string, le: string}  $reference
     */
    private function miseAJourEnCours(array $reference): bool
    {
        return Carbon::parse($reference['depuis'])->greaterThan(now()->subMinutes(self::MINUTES_DE_MISE_A_JOUR));
    }

    /**
     * Ce qui distingue deux images : deux constructions de `main` portent la
     * même version et diffèrent par leur révision.
     *
     * @param  array{version: string, revision: string, depuis: string, le: string}  $annonce
     */
    private function identite(array $annonce): string
    {
        return $annonce['version'].'@'.$annonce['revision'];
    }

    /**
     * @param  array{version: string, revision: string, depuis: string, le: string}  $annonce
     */
    private function libelle(array $annonce): string
    {
        if ($annonce['revision'] === '' || $annonce['revision'] === 'inconnue') {
            return $annonce['version'];
        }

        return sprintf('%s (%s)', $annonce['version'], substr($annonce['revision'], 0, 7));
    }

    /**
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}|null>  $annonces
     * @param  array{version: string, revision: string, depuis: string, le: string}|null  $reference
     * @param  list<string>  $enRetard
     * @param  list<string>  $absents
     */
    private function resume(array $annonces, ?array $reference, array $enRetard, array $absents): string
    {
        if ($reference !== null && array_merge($enRetard, $absents) === []) {
            return $this->libelle($reference).' partout';
        }

        return implode(' · ', array_map($this->libelleDuConteneur(...), array_keys($annonces), $annonces));
    }

    /**
     * @param  array{version: string, revision: string, depuis: string, le: string}|null  $annonce
     */
    private function libelleDuConteneur(string $conteneur, ?array $annonce): string
    {
        return $conteneur.' '.($annonce === null ? '?' : $this->libelle($annonce));
    }

    /**
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @param  array{version: string, revision: string, depuis: string, le: string}  $reference
     * @param  list<string>  $enRetard
     */
    private function ecart(array $presentes, array $reference, array $enRetard): string
    {
        $phrases = [];

        foreach ($enRetard as $conteneur) {
            $annonce = $presentes[$conteneur];
            $phrases[] = sprintf(
                '%s exécute %s depuis le %s (dernière annonce le %s)',
                $conteneur,
                $this->libelle($annonce),
                $this->date($annonce['depuis']),
                $this->date($annonce['le']),
            );
        }

        $aJour = array_keys(array_diff_key($presentes, array_flip($enRetard)));

        return sprintf(
            '%s ; %s %s depuis le %s.',
            implode(' ; ', $phrases),
            $this->enumerer($aJour),
            $this->libelle($reference),
            $this->date($reference['depuis']),
        );
    }

    private function date(string $iso8601): string
    {
        $date = Carbon::parse($iso8601)->setTimezone(config()->string('app.timezone'));

        return $date->format('d/m/Y').' à '.$date->format('H:i');
    }

    /**
     * « app », « app et worker », « app, worker et scheduler ».
     *
     * @param  list<string>  $noms
     */
    private function enumerer(array $noms): string
    {
        $dernier = array_pop($noms);

        return $noms === [] ? (string) $dernier : implode(', ', $noms).' et '.$dernier;
    }
}
