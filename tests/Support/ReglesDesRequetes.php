<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Stringable;
use Symfony\Component\Finder\Finder;

/**
 * Les règles de validation des requêtes de `app/Http/Requests`, lues telles
 * que Laravel les applique, pour les gardes qui les confrontent à une borne
 * ou à la base.
 *
 * Les règles sont celles que rend `rules()` sur une requête vide, sans
 * utilisateur ni route : aucune requête de l'application n'en change les
 * bornes selon l'appelant. Une règle écrite `'a|b'` est découpée comme Laravel
 * la découpe ; un objet qui sait s'écrire en texte (`Rule::in()`,
 * `Rule::numeric()`) l'est par son `__toString()`, les autres restent des
 * objets.
 */
final class ReglesDesRequetes
{
    /**
     * Chaque requête de validation, avec son fichier.
     *
     * @return array<class-string<FormRequest>, string>
     */
    public static function toutes(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php')->notPath('Concerns') as $fichier) {
            $classe = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

            if (is_subclass_of($classe, FormRequest::class)) {
                $classes[$classe] = (string) $fichier->getRealPath();
            }
        }

        ksort($classes);

        return $classes;
    }

    /**
     * Les règles de chaque champ d'une requête, une par élément.
     *
     * @param  class-string<FormRequest>  $classe
     * @return array<string, list<string|object>>
     */
    public static function de(string $classe): array
    {
        $requete = new $classe();
        $requete->setContainer(app());

        $regles = method_exists($requete, 'rules') ? app()->call([$requete, 'rules']) : [];
        $parChamp = [];

        foreach (is_array($regles) ? $regles : [] as $champ => $regle) {
            $parChamp[(string) $champ] = self::enListe($regle);
        }

        return $parChamp;
    }

    /**
     * Le champ demande-t-il un nombre ?
     *
     * @param  list<string|object>  $regles
     */
    public static function estNumerique(array $regles): bool
    {
        foreach (self::textes($regles) as $regle) {
            $nom = strtolower(explode(':', $regle, 2)[0]);

            if (in_array($nom, ['integer', 'int', 'numeric', 'decimal'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les bornes basse et haute que posent les règles d'un champ, ou null.
     *
     * `min:`, `max:`, `between:`, `gte:`/`lte:` d'une valeur, et `in:` (une
     * liste fermée borne des deux côtés) ; `size:` fixe les deux.
     *
     * @param  list<string|object>  $regles
     * @return array{min: float|null, max: float|null}
     */
    public static function bornes(array $regles): array
    {
        $min = null;
        $max = null;

        foreach (self::textes($regles) as $regle) {
            [$nom, $parametres] = array_pad(explode(':', $regle, 2), 2, '');
            $valeurs = array_map(trim(...), explode(',', $parametres));
            $nombres = array_values(array_filter(array_map(
                static fn (string $valeur): ?float => is_numeric(trim($valeur, '"')) ? (float) trim($valeur, '"') : null,
                $valeurs,
            ), static fn (?float $valeur): bool => $valeur !== null));

            switch (strtolower($nom)) {
                case 'min':
                case 'gte':
                    $min = $nombres[0] ?? $min;
                    break;
                case 'max':
                case 'lte':
                    $max = $nombres[0] ?? $max;
                    break;
                case 'between':
                    $min = $nombres[0] ?? $min;
                    $max = $nombres[1] ?? $max;
                    break;
                case 'size':
                    $min = $max = $nombres[0] ?? $max;
                    break;
                case 'in':
                    if ($nombres !== [] && count($nombres) === count($valeurs)) {
                        $min = min($nombres);
                        $max = max($nombres);
                    }
                    break;
            }
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Les règles d'un champ qui s'écrivent en texte.
     *
     * @param  list<string|object>  $regles
     * @return list<string>
     */
    public static function textes(array $regles): array
    {
        $textes = [];

        foreach ($regles as $regle) {
            if (is_string($regle)) {
                $textes[] = $regle;
            } elseif ($regle instanceof Stringable) {
                array_push($textes, ...explode('|', (string) $regle));
            }
        }

        return $textes;
    }

    /**
     * @return list<string|object>
     */
    private static function enListe(mixed $regle): array
    {
        if (is_string($regle)) {
            return explode('|', $regle);
        }

        if (is_object($regle)) {
            return [$regle];
        }

        if (! is_array($regle)) {
            return [];
        }

        $liste = [];

        foreach ($regle as $element) {
            if (is_string($element) || is_object($element)) {
                $liste[] = $element;
            }
        }

        return $liste;
    }
}
