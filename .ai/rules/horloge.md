---
paths:
  - 'app/**'
---

# Horloge

## Une opération lit l'horloge une fois
Réglé le 2026-10-11 (#2033). Tout ce qu'une même opération tire de l'heure (un nom et une date, une clef de cache et la fenêtre qu'elle garde, les cartes voisines d'une page, l'année et le mois, le jour mis en avant dans une grille) vient d'une seule lecture : `$maintenant = now()` en tête, puis `$maintenant->copy()->…` (le Carbon de Laravel est mutable), ou l'instant passé aux méthodes et aux services qui en dépendent (`VolumeStatsService::getMonthlyVolumeHistory()` le reçoit en dernier argument). Une méthode qui relit l'horloge en cachette compte aussi : `isToday()`, `isPast()`, `diffForHumans()`, `diffInDays()` sans argument. Quand minuit, la fin du mois ou celle de l'année passaient entre deux lectures, la séance ouverte commençait la veille sous le nom du lendemain (`CreateWorkoutAction`), la fréquence mensuelle de la page des séances comptait d'avril à septembre à côté d'une voisine de mai à octobre, et l'entrée rangée sous septembre gardait la fenêtre d'octobre (`FetchWorkoutsIndexAction`), le calendrier ouvert le 31 décembre au soir montrait janvier de l'année qui s'achevait (`CalendarController`), la grille des habitudes ne mettait plus aucun jour en avant (`FetchHabitsIndexAction`), et les tuiles du panneau comptaient deux jours ou deux ans (`StatsOverview`, `UserActivityChart`). La règle des deux bornes d'une fenêtre (`.ai/rules/mutation.md`) en est un cas.

Reste permise une seconde lecture qui ne décide de rien de visible : la durée d'une entrée de cache (`now()->addMinutes(30)` à côté d'une fenêtre lue ailleurs ne décale que l'expiration, de quelques microsecondes), les horodatages qu'Eloquent pose à l'écriture, ou deux dates d'audit d'une même écriture que rien ne compare.

Pour le prouver, `tests/Support/HorlogeQuiPasseMinuit.php` fait passer minuit après la n-ième lecture, cache compris, et un jeu de données essaie chacune des lectures de l'opération (`UneLectureDeLHorlogeParOperationTest`, dans tests/Feature, en essaie dix sur la page des séances). Une requête HTTP lit l'horloge bien avant le contrôleur : le test pose l'horloge au moment où le conteneur résout l'action que le contrôleur reçoit (`$this->app->resolving(...)`), juste avant sa première lecture. Une méthode privée d'un widget du panneau s'appelle par `ReflectionMethod`, sans passer par le rendu Livewire, qui lit l'horloge un nombre de fois qu'on ne maîtrise pas.
