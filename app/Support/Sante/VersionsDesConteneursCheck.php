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
 * jamais annoncé. La dernière image déployée est celle dont la première
 * annonce est la plus récente : un conteneur en retard s'annonce peut-être
 * chaque minute, mais toujours depuis la même date.
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
     * Le libellé de la page « Santé », qui découperait sinon le nom de classe.
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
        // Lues à chaque passage : une annonce lue au démarrage du processus
        // resterait celle d'avant la mise à jour.
        $annonces = [];

        foreach (AnnonceDeVersion::CONTENEURS as $conteneur) {
            $annonces[$conteneur] = AnnonceDeVersion::lue($conteneur);
        }

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

        $messages = [];

        if ($reference !== null && $enRetard !== []) {
            $messages[] = $this->ecart($presentes, $reference, $enRetard);
        }

        if ($absents !== []) {
            $messages[] = sprintf(
                '%s ne %s jamais annoncé%s : arrêté, ou sur une image antérieure à ce contrôle.',
                $this->enumerer($absents),
                count($absents) > 1 ? 'se sont' : 's\'est',
                count($absents) > 1 ? 's' : '',
            );
        }

        if ($messages === []) {
            return $result->ok();
        }

        $message = implode(' ', $messages).' '.self::QUOI_FAIRE;

        if ($enRetard !== [] && $reference !== null && ! $this->miseAJourEnCours($reference)) {
            return $result->failed($message);
        }

        if ($enRetard !== []) {
            return $result->warning('Peut-être une mise à jour en cours : '.$message);
        }

        return $result->warning($message);
    }

    /**
     * L'annonce de la dernière image déployée : celle que son conteneur
     * exécute depuis le moins longtemps.
     *
     * @param  array<string, array{version: string, revision: string, depuis: string, le: string}>  $presentes
     * @return array{version: string, revision: string, depuis: string, le: string}|null
     */
    private function derniereImageDeployee(array $presentes): ?array
    {
        $reference = null;

        foreach ($presentes as $annonce) {
            if ($reference === null || Carbon::parse($annonce['depuis'])->greaterThan(Carbon::parse($reference['depuis']))) {
                $reference = $annonce;
            }
        }

        return $reference;
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
        if ($reference !== null && $enRetard === [] && $absents === []) {
            return $this->libelle($reference).' partout';
        }

        $parConteneur = [];

        foreach ($annonces as $conteneur => $annonce) {
            $parConteneur[] = $conteneur.' '.($annonce === null ? '?' : $this->libelle($annonce));
        }

        return implode(' · ', $parConteneur);
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
