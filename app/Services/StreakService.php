<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/**
 * La série de jours d'un compte : la suite de jours consécutifs qui finit à
 * sa dernière séance faite, la plus longue de ces suites, et le début de
 * cette dernière séance (`users.current_streak`, `users.longest_streak`,
 * `users.last_workout_at`).
 *
 * Un jour compte quand il a au moins une séance FAITE : terminée, avec au
 * moins une série cochée (`User::seancesFaites()`), la définition des succès
 * de séances et des objectifs de fréquence (#2110). Le jour d'une séance est
 * celui de son début, dans le fuseau de l'application. La série comptait
 * toute séance dès son démarrage : démarrer une séance trois jours de suite,
 * puis la terminer sans rien soulever, faisait une série de trois jours et
 * débloquait « Série de 3 » (#2171).
 *
 * Un seul écrivain, la reconstruction depuis les séances faites
 * (`recalculerDepuisLesFaits()`), que les écouteurs des séances, des lignes
 * et des séries appellent quand une séance peut devenir faite ou cesser de
 * l'être (`AppServiceProvider`). L'avance d'un cran à la création d'une
 * séance, le second écrivain, comptait la séance avant de savoir si elle
 * serait faite, et ne savait rien retirer.
 */
final class StreakService
{
    /**
     * La série en cours, telle qu'elle doit être AFFICHÉE.
     *
     * `users.current_streak` ne s'écrit que lorsqu'une séance devient faite ou
     * cesse de l'être. Passé un jour sans séance faite, il continue d'annoncer
     * la valeur d'hier : la péremption n'est visible qu'à la lecture.
     *
     * `HandleInertiaRequests` faisait déjà cette correction, en ligne ;
     * `UserResource` rendait la valeur brute. Le même utilisateur avait donc
     * deux séries différentes selon que la page était rendue par Inertia ou lue
     * par l'API, et c'est le même client qui emprunte les deux. Une seule
     * définition, appelée par les deux.
     *
     * Premier pas de la trajectoire décrite dans la note de modélisation :
     * la grandeur dérivée cesse d'être lue directement, sans que la colonne
     * disparaisse encore.
     */
    public function currentStreakFor(User $user): int
    {
        if ($user->last_workout_at === null) {
            return 0;
        }

        $joursDepuis = $user->last_workout_at->startOfDay()->diffInDays(now()->startOfDay());

        return $joursDepuis > 1 ? 0 : $user->current_streak;
    }

    /**
     * Reconstruit la série du compte de cette séance, relu.
     *
     * Les écouteurs des séances, des lignes et des séries l'appellent quand la
     * séance peut devenir faite ou cesser de l'être. Le compte se relit : une
     * séance chargée avant qu'un autre écrivain ne réécrive la série (la série
     * cochée qui l'a faite, une autre séance du compte) garde le compte
     * d'alors, et la reconstruction, qui n'écrit que ce qui diffère de lui,
     * pouvait n'écrire rien sur une série pourtant fausse en base. Les chemins
     * de l'application chargent ce compte à ce moment-là : la relecture ne
     * leur coûte aucune requête. Le compte relu reste sur la séance, pour les
     * écouteurs qui suivent.
     */
    public function recalculerPourLaSeance(Workout $seance): void
    {
        $seance->unsetRelation('user');

        $compte = $seance->user;

        if ($compte instanceof User) {
            $this->recalculerDepuisLesFaits($compte);
        }
    }

    /**
     * Reconstruit la série depuis les séances faites qui existent, et l'écrit.
     *
     * Le seul écrivain de la série de jours. L'avance d'un cran à la création
     * d'une séance, qui l'a précédé, ne savait qu'avancer depuis
     * `last_workout_at` : une séance supprimée laissait la date pointer une
     * séance disparue, et l'écart calculé à la séance suivante partait d'une
     * date fantôme (#1460) ; une séance saisie après coup ne comblait pas un
     * trou ; une séance déplacée gardait son jour de départ (#1983) ; et une
     * séance comptait dès son démarrage, vide ou non (#2171). La
     * reconstruction est juste dans chacun de ces cas : une séance ajoutée,
     * retirée ou déplacée au milieu peut allonger une suite, la couper ou en
     * souder deux.
     *
     * Une série qui ne change pas ne s'écrit pas : `save()` n'émet aucune
     * requête quand `getDirty()` est vide.
     */
    public function recalculerDepuisLesFaits(User $user): void
    {
        $serie = $this->serieDepuisLesFaits($user);

        $user->forceFill([
            'last_workout_at' => $serie['derniere'],
            'current_streak' => $serie['enCours'],
            'longest_streak' => $serie['plusLongue'],
        ])->save();
    }

    /**
     * La série que donnent les séances faites qui existent, sans rien écrire.
     *
     * `recalculerDepuisLesFaits()` l'écrit ; le contrôle nocturne la compare à
     * ce qui est stocké (#1983). Un seul calcul pour les deux : un contrôle qui
     * reconstruirait à sa façon pourrait donner tort à l'écrivain qui a raison.
     *
     * Le calcul part des jours distincts des séances faites, du plus récent au
     * plus ancien : la série en cours est la suite consécutive qui se termine à
     * la dernière séance faite, la plus longue est la plus longue suite
     * trouvée. Le jour est la date de `started_at` telle que la base la rend,
     * dans le fuseau de l'application : une séance commencée à 23 h 50 et
     * terminée après minuit compte pour le jour de son début.
     *
     * Le tri se fait en PHP plutôt qu'en SQL : `DATE(started_at)` s'écrirait
     * différemment selon le pilote, et une séance par jour reste un volume
     * qu'on ordonne sans y penser.
     *
     * @return array{derniere: string|null, enCours: int, plusLongue: int}
     */
    public function serieDepuisLesFaits(User $user): array
    {
        /*
         * Une seule lecture, sans hydratation et sans fonction sur la colonne.
         *
         * Les séances faites du compte (`User::seancesFaites()`), et rien
         * d'autre : la série comptait toutes les séances, vides ou en cours
         * (#2171). Lue nue, la colonne laisse `workouts(user_id, started_at)`
         * servir le filtre ET l'ordre, la semi-jointure s'arrêtant à la
         * première série cochée de chaque séance, et le dédoublonnage des
         * jours se fait sur des chaînes déjà triées. Un `DISTINCT
         * DATE(started_at)` empêcherait l'index de rendre les lignes
         * ordonnées : table temporaire et tri.
         */
        /** @var list<string> $horodatages */
        $horodatages = $user->seancesFaites()
            ->toBase()
            ->orderByDesc('started_at')
            ->pluck('started_at')
            ->all();

        $derniere = $horodatages[0] ?? null;

        if ($derniere === null) {
            return ['derniere' => null, 'enCours' => 0, 'plusLongue' => 0];
        }

        $enCours = 1;
        $plusLongue = 1;
        $suite = 1;

        /*
         * Une rupture rencontree en remontant le temps ferme la serie EN COURS,
         * definitivement. Ce que la boucle continue de compter apres, ce sont
         * des series passees, qui n'interessent plus que `$plusLongue`.
         *
         * Le garde ecrit ici etait `$consecutives && $enCours === $suite - 1`,
         * cense dire « aucune rupture depuis la derniere seance ». Il ne le
         * disait pas : par recurrence, `$enCours` vaut TOUJOURS `$suite - 1` a
         * l'entree d'une paire consecutive, y compris apres une rupture ou le
         * compteur est reparti de 1. Une vieille serie de trois jours faisait
         * donc passer pour trois une derniere seance isolee.
         */
        $rupture = false;
        $veille = null;

        foreach ($horodatages as $horodatage) {
            $jour = mb_substr($horodatage, 0, 10);

            // Deux seances le meme jour ne comptent qu'une fois. Les
            // horodatages arrivant tries, le doublon est toujours adjacent.
            if ($jour === $veille) {
                continue;
            }

            if ($veille !== null) {
                if (Carbon::parse($jour)->addDay()->toDateString() === $veille) {
                    $suite++;
                } else {
                    $suite = 1;
                    $rupture = true;
                }

                $plusLongue = max($plusLongue, $suite);

                if (! $rupture) {
                    $enCours = $suite;
                }
            }

            $veille = $jour;
        }

        return ['derniere' => $derniere, 'enCours' => $enCours, 'plusLongue' => $plusLongue];
    }
}
