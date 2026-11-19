# Tests de mutation

Cette page dit comment mesurer le score de mutation d'une classe en local, et pourquoi la passe nocturne est découpée en parts. Les seuils par classe sont dans [Développement](developpement.md#qualité).

## Mesurer une classe

Le seuil n'est appliqué que par `vendor/bin/pest`. `artisan test --mutate --min` accepte l'option et l'ignore : c'est le `--min` de la **couverture**, silencieusement absorbé.

1. Si les bases par processus n'existent pas, lancer une fois `./vendor/bin/sail artisan test -p` : il les crée au passage.
2. Lancer la mutation d'une classe ou d'un espace de noms :

   ```bash
   ./vendor/bin/sail php vendor/bin/pest --mutate --parallel --covered-only \
     --class='App\Policies' --min=99
   ```

3. Pour conclure qu'une classe est propre, refaire la mesure sans `--parallel` (voir ci-dessous) :

   ```bash
   ./vendor/bin/sail php vendor/bin/pest --mutate --covered-only --class='App\Services\StreakService'
   ```

## En local, `--parallel` sert à itérer, pas à conclure

Pest accorde à chaque mutant la durée de la passe de référence plus 20 % (au moins 5 s), et compte un dépassement comme un mutant tué.

Cette référence est mesurée hors contention. Une machine de dev qui lance dix processus la dépasse d'elle-même dès que l'ensemble couvrant est gros : c'est le cas de tout service branché sur un observateur, couvert par des centaines de tests.

Mesure sur `App\Services\StreakService`, même code, mêmes mutations :

| Mode | Verdicts | Score |
| --- | --- | --- |
| `--parallel` (10 processus) | 19 timeout, 21 tués, **0 survivant** | 100,00 % |
| séquentiel | 0 timeout, 37 tués, **3 survivants** | 92,50 % |

**L'erreur ne va que dans un sens :** le parallèle **cache** des survivants, il n'en invente pas. Il reste donc bon pour trouver du travail, mais « cette classe est propre » demande une mesure séquentielle.

## La passe nocturne

La passe nocturne n'est pas concernée par cette erreur : le runner ne lance qu'un processus par cœur (« Parallel: 4 processes » dans chaque rapport), sans autre charge, et sa passe de référence se mesure dans les mêmes conditions que ses mutants. Sur trois nuits consécutives : 1 timeout sur les 841 mutations de `App\Services`, 4 sur les 887 de `App\Actions`, 0 sur `App\Policies`.

### Sa durée suit le runner

Un runner lent exécute chaque test plus lentement, et chaque mutant, qui rejoue les tests couvrant sa ligne, le paie. À code égal, `App\Policies` a pris 26 minutes sur un runner et 39 sur un autre.

C'est pourquoi la passe est découpée en parts, chacune avec sa limite de temps :

- `App\Services` compte cinq parts, pour sept en tout ;
- la limite de chaque part est le double de la plus longue de ses mesures ;
- chaque rapport nomme ses dix mutants les plus lents (`--profile`) ;
- les mesures sont en tête du job dans `.github/workflows/mutation.yml`.

Une part qui dépasse sa limite est annulée, et ouvre une issue comme une part rouge.
