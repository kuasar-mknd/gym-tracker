<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing user achievements.
 */
final class AchievementService
{
    /**
     * Synchronize all achievements for a user.
     */
    public function syncAchievements(User $user): void
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Use direct DB query for pivot IDs to avoid unnecessary JOIN with achievements table.
        $unlockedIds = DB::table('user_achievements')
            ->where('user_id', $user->id)
            ->pluck('achievement_id')
            ->toArray();

        // ⚡ Bolt Optimization: Use cached all() collection and filter in-memory
        // Impact: Eliminates a database query during the frequently called sync operation
        $locked = Achievement::getCachedAll()->whereNotIn('id', $unlockedIds)->values();

        if ($locked->isEmpty()) {
            return;
        }

        $metrics = $this->preCalculateMetrics($user, $locked);

        $toUnlockIds = [];
        $unlockedAchievements = [];

        foreach ($locked as $achievement) {
            if ($this->isUnlocked($achievement, $metrics)) {
                $toUnlockIds[] = $achievement->id;
                $unlockedAchievements[] = $achievement;
            }
        }

        /*
         * Un seul `attach` pour tout le lot, plutot qu'un par succes. Il n'y a
         * pas de garde sur le tableau vide : `attach([])` n'ecrit rien de
         * lui-meme, Laravel court-circuitant l'insertion, et la boucle de
         * notification ne tourne pas non plus. Le garde qui etait la ne
         * changeait donc rien d'observable, et ses deux mutants survivaient pour
         * cette seule raison. Un test verifie qu'aucune ecriture n'a lieu.
         */
        $user->achievements()->attach($toUnlockIds, ['achieved_at' => now()]);

        /*
         * `AchievementUnlocked::via()` lit les preferences, et `via()` est
         * appele EN SYNCHRONE a la mise en file, meme pour une notification
         * `ShouldQueue`. La relation n'etant jamais chargee, c'etait un EXISTS
         * par succes debloque.
         */
        $user->loadMissing('notificationPreferences');

        foreach ($unlockedAchievements as $achievement) {
            $user->notify(new \App\Notifications\AchievementUnlocked($achievement));
        }
    }

    /**
     * Un succes est debloque quand la metrique de son type atteint son seuil.
     *
     * Les metriques sont indexees par type de succes, il n'y a donc plus de
     * table de correspondance a tenir a jour. L'ancienne version ecrivait la
     * meme comparaison quatre fois, chacune avec un repli `?? 0` — huit mutants
     * y survivaient, tous pour la meme raison : le repli est inatteignable.
     * `preCalculateMetrics` recoit exactement les succes verrouilles et calcule
     * la metrique de chaque type qui s'y trouve, donc la cle du succes examine
     * existe toujours.
     *
     * Une cle absente designe un type que le service ne sait pas evaluer. Rien
     * n'empeche d'en mettre un en base — `achievements.type` est une colonne
     * texte libre — et il ne doit alors rien debloquer, seuil zero compris.
     *
     * @param  array<string, int|float>  $metrics  Metriques pre-calculees, indexees par type de succes.
     */
    private function isUnlocked(Achievement $achievement, array $metrics): bool
    {
        if (! array_key_exists($achievement->type, $metrics)) {
            return false;
        }

        return $metrics[$achievement->type] >= $achievement->threshold;
    }

    /**
     * Calcule, une seule fois, les metriques dont les succes verrouilles ont besoin.
     *
     * Seuls les types presents dans le lot sont calcules : une fois le dernier
     * succes de serie acquis, plus personne ne paie sa requete.
     *
     * @param  Collection<int, Achievement>  $achievements  Les succes encore verrouilles.
     * @return array<string, int|float> Metriques indexees par type de succes.
     */
    private function preCalculateMetrics(User $user, Collection $achievements): array
    {
        $types = $achievements->pluck('type')->unique();
        $metrics = [];

        if ($types->contains('count')) {
            // `toBase()` evite d'hydrater des modeles pour lire un compte.
            $metrics['count'] = $user->workouts()->toBase()->count();
        }

        if ($types->contains('weight_record')) {
            $metrics['weight_record'] = $this->calculateMaxWeight($user);
        }

        if ($types->contains('volume_total')) {
            $metrics['volume_total'] = $this->calculateTotalVolume($user);
        }

        if ($types->contains('streak')) {
            $metrics['streak'] = $this->calculateMaxStreak($this->getUniqueWorkoutDates($user));
        }

        return $metrics;
    }

    /**
     * Calculate the maximum weight lifted by a user.
     *
     * @param  User  $user  The user to calculate the max weight for.
     * @return float The maximum weight lifted.
     */
    private function calculateMaxWeight(User $user): float
    {
        /** @var float|null $maxWeight */
        $maxWeight = $user->personalRecords()
            // ⚡ Bolt: PERFORMANCE OPTIMIZATION
            // Use toBase() to bypass Eloquent model hydration and overhead.
            ->toBase()
            ->where('type', 'max_weight')
            ->max('value');

        return (float) ($maxWeight ?? 0.0);
    }

    /**
     * Calculate the total volume lifted by a user.
     *
     * @param  User  $user  The user to calculate the total volume for.
     * @return float The total volume lifted.
     */
    private function calculateTotalVolume(User $user): float
    {
        return $user->volumeSouleve();
    }

    /**
     * Toutes les dates distinctes auxquelles l'utilisateur s'est entraine, de la
     * plus recente a la plus ancienne.
     *
     * Sans borne temporelle, et c'est le point : la requete cherchait dans les
     * `seuil + 30` derniers jours, si bien qu'une serie plus ancienne ne
     * debloquait plus rien. Trois jours consecutifs il y a deux mois, puis plus
     * rien : `streak-3` restait verrouille pour toujours, sans que rien ne le
     * dise. Un succes se merite une fois — la question posee est « as-tu deja
     * enchaine N jours », pas « les as-tu enchaines recemment ».
     *
     * La requete ne coute rien de plus en pratique : elle ne tourne que tant
     * qu'un succes de serie est encore verrouille, et s'arrete d'elle-meme des
     * qu'il est acquis, puisque les succes deja obtenus sortent de `$locked`.
     *
     * @return list<string> Dates au format 'Y-m-d'.
     */
    private function getUniqueWorkoutDates(User $user): array
    {
        /*
         * La colonne NUE, dedoublonnee en PHP.
         *
         * `DISTINCT DATE(started_at)` appliquait une fonction a la colonne :
         * l'index ne rendait plus les lignes deja ordonnees, d'ou une table
         * temporaire et un tri — `Using temporary; Using filesort` au plan.
         * Mesure aux compteurs `Handler_read_*` : 98 lectures a 40 seances,
         * 418 a 200, sur un chemin que chaque enregistrement de seance emprunte
         * tant qu'un succes de serie reste verrouille.
         *
         * Lue nue, `workouts(user_id, started_at)` sert le filtre ET l'ordre.
         * Les doublons d'un meme jour sont alors adjacents, donc un seul passage
         * suffit — et la double ecriture selon le pilote disparait, la colonne
         * se lisant partout de la meme facon. Meme correctif qu'en #1600.
         */
        /** @var list<string> $horodatages */
        $horodatages = $user->workouts()
            ->toBase()
            ->orderByDesc('started_at')
            ->pluck('started_at')
            ->all();

        $dates = [];
        $veille = null;

        foreach ($horodatages as $horodatage) {
            $jour = mb_substr($horodatage, 0, 10);

            if ($jour === $veille) {
                continue;
            }

            $dates[] = $jour;
            $veille = $jour;
        }

        return $dates;
    }

    /**
     * Le plus long enchainement de jours consecutifs dans une liste de dates.
     *
     * Une date qui est la veille de la precedente prolonge la serie en cours ;
     * toute autre en ouvre une nouvelle. Partir de 0 fait repondre 0 sur une
     * liste vide, sans garde separe — l'ancienne version en avait un
     * (`if ($count <= 1) { return $count; }`) qui ne changeait rien : pour une
     * seule date le chemin general rendait deja 1, et zero date n'y arrivait
     * jamais. Ses six mutants survivaient tous pour cette raison.
     *
     * La comparaison porte sur des chaines 'Y-m-d', pas sur des timestamps.
     * L'ancienne version divisait un ecart de secondes par 86400 et arrondissait
     * pour rattraper les nuits de 23 h et de 25 h — l'application vit en
     * Europe/Paris, le cas se produit deux fois par an. Comparer des jours
     * calendaires supprime le probleme au lieu de le compenser : quatre mutants
     * y survivaient (`floor`, `ceil`, 86399, 86401) parce qu'aucun test ne
     * passait un changement d'heure, et il n'y a plus rien a y arrondir.
     *
     * @param  list<string>  $dates  Dates 'Y-m-d' distinctes, de la plus recente a la plus ancienne.
     */
    private function calculateMaxStreak(array $dates): int
    {
        $maxStreak = 0;

        /*
         * Cette valeur initiale n'est jamais lue : `$expectedPreviousDay` vaut
         * null au premier tour, la condition est donc fausse et le compteur part
         * a 1. Aucun test ne peut distinguer 0 de 1 ou de -1 ici, et un test qui
         * pretendrait le faire verifierait en realite autre chose.
         *
         * @pest-mutate-ignore
         */
        $currentStreak = 0;
        $expectedPreviousDay = null;

        foreach ($dates as $date) {
            $currentStreak = $date === $expectedPreviousDay ? $currentStreak + 1 : 1;
            $maxStreak = max($maxStreak, $currentStreak);
            $expectedPreviousDay = $this->dayBefore($date);
        }

        return $maxStreak;
    }

    /**
     * La veille d'une date 'Y-m-d', au meme format.
     *
     * Ancre en UTC a dessein : le calcul porte sur des jours calendaires, et un
     * fuseau a heure d'ete rendrait « la veille » ambigue.
     */
    private function dayBefore(string $date): string
    {
        $day = new DateTimeImmutable($date, new DateTimeZone('UTC'));

        return $day->sub(new DateInterval('P1D'))->format('Y-m-d');
    }
}
