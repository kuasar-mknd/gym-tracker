<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TexteSansAdresse;
use BezhanSalleh\FilamentExceptions\Models\Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Une exception gardée en base pour le panneau, sans ce qu'une requête porte
 * de secret : les cookies ne sont pas gardés, et les champs, les en-têtes et
 * les paramètres de route sensibles sont masqués avant l'écriture
 * (`CLEFS_MASQUEES`).
 *
 * Sensibles : les mots de passe et les jetons, et (#PR) l'identifiant
 * qu'une personne tape pour se connecter ou réinitialiser son mot de passe
 * (`email`, et `adresse`, la valeur chiffrée du lien de réinitialisation),
 * comme le mot de passe : le paquet garde le corps entier de la requête
 * (`request()->all()`), et une adresse tapée à la connexion, qui n'est pas
 * forcément celle d'un compte, voire un mot de passe tapé dans le mauvais
 * champ, y restait trente jours en clair. Et l'en-tête `Referer`, qui porte
 * la page d'où part l'envoi : celle d'un lien de réinitialisation porte le
 * lien lui-même, jeton et adresse chiffrée. Le jeton, paramètre de route,
 * est aussi un segment du chemin enregistré, masqué de même
 * (`masquerLeChemin()`).
 *
 * Une clef se reconnaît sous toutes ses écritures (`formeComparable()`) :
 * casse, `_` et `-` n'y comptent pas, et seul compte le dernier segment d'un
 * chemin à points. Livewire envoie ainsi la saisie d'un formulaire du
 * panneau sous `components.*.updates`, `data.password` ou
 * `data.currentPassword`, que la seule clef `password` laissait en clair, et
 * l'état du composant dans un instantané, du JSON écrit en texte
 * (`CLEFS_EN_JSON`), qui porte le mot de passe d'un essai refusé : il est lu,
 * masqué comme le reste, puis réécrit.
 *
 * Le message et le texte Markdown, eux, se lisent sans clef : toute adresse
 * de courriel y devient « [adresse masquée] » (`TexteSansAdresse`). Les
 * erreurs d'envoi n'en citent plus (`CourrielleurQuiTaitLesAdresses`), et
 * c'est une défense contre celles d'un paquet qui en citerait une.
 *
 * Et à taille bornée : le paquet garde le corps entier de la requête, le
 * message d'une exception peut recopier une valeur reçue, que le texte
 * Markdown répète, et hors de l'image la pile garde les arguments de chaque
 * appel (#2032). Une requête de plusieurs mégaoctets qui finit en erreur
 * écrivait une ligne aussi grosse qu'elle. Chaque colonne est désormais
 * coupée à un budget (`OCTETS_MAX_PAR_COLONNE`), de sorte qu'une ligne ne
 * dépasse jamais 128 Kio, quel que soit le corps reçu (`ExceptionsTest`). Ce qui
 * est coupé le dit : un texte finit par « [tronqué] », un objet JSON porte une
 * clé `…` qui compte ce qui manque. Une liste (pile, requêtes SQL) garde ses
 * premiers éléments sans marque, parce que le panneau lit chacun comme un
 * tableau : le haut de la pile, là où l'exception est levée, reste.
 *
 * Une exception levée par une requête d'un compte connecté le désigne, et
 * souvent recopie ce qu'il a saisi : l'en-tête `X-Compte-De-L-Ecriture` des
 * écritures rejouées porte son identifiant, les paramètres de route le modèle
 * lié (son `user_id` et ses champs), le message d'une erreur SQL les valeurs
 * envoyées. `user_id` la rattache donc au compte de la requête
 * (`compteDeLaRequete()`), et la clé l'efface avec lui (#1938). Les autres,
 * levées avant l'authentification, sous le panneau, en file ou en console,
 * n'ont pas de lien et peuvent porter les mêmes traces : elles partent à
 * trente jours, par la purge que le paquet planifie (`modelPruneInterval()`
 * du panneau), comme les lignes écrites avant la colonne.
 *
 * @property int|null $user_id
 */
final class ExceptionEnregistree extends Exception
{
    /**
     * Le budget de chaque colonne longue, en octets (texte, ou JSON tel que
     * Laravel l'écrit). La pile et le Markdown sont les plus utiles à lire,
     * et les plus gros d'ordinaire : ils ont la plus grande part.
     *
     * @var array<string, int>
     */
    public const array OCTETS_MAX_PAR_COLONNE = [
        'message' => 8_192,
        'markdown' => 24_576,
        'trace' => 24_576,
        'body' => 8_192,
        'headers' => 8_192,
        'query' => 12_288,
        'route_context' => 2_048,
        'route_parameters' => 4_096,
    ];

    /**
     * La longueur des colonnes textuelles courtes, en caractères, celle de
     * leur migration : au-delà, MySQL refusait l'écriture, et l'exception
     * n'était pas gardée du tout.
     *
     * @var array<string, int>
     */
    private const array CARACTERES_MAX_PAR_COLONNE = [
        'type' => 255,
        'code' => 255,
        'file' => 255,
        'method' => 10,
        'path' => 2_048,
        'ip' => 45,
    ];

    /** Le plus d'octets que garde une valeur d'un objet JSON (un champ du corps, un en-tête). */
    private const int OCTETS_MAX_PAR_VALEUR = 1_024;

    /** Le plus d'octets que garde une clé d'un objet JSON. */
    private const int OCTETS_MAX_PAR_CLE = 128;

    /** Au-delà de cette profondeur, un tableau imbriqué n'est plus gardé. */
    private const int PROFONDEUR_MAX = 16;

    /**
     * Les clefs dont la valeur ne s'écrit jamais, sous leur forme comparable
     * (`formeComparable()`) : `_token` et `password_confirmation` s'y lisent
     * `token` et `passwordconfirmation`, et `data.currentPassword` de
     * Livewire `currentpassword`.
     *
     * Les en-têtes `php-auth-*` sont ceux que Symfony tire de
     * `Authorization` (`ServerBag::getHeaders()`) : l'identifiant et le mot
     * de passe d'une authentification Basic, en clair, ou l'en-tête Digest
     * recopié.
     *
     * @var list<string>
     */
    private const array CLEFS_MASQUEES = [
        'password',
        'passwordconfirmation',
        'currentpassword',
        'token',
        'secret',
        'authorization',
        'phpauthuser',
        'phpauthpw',
        'phpauthdigest',
        'cookie',
        'xcsrftoken',
        'xxsrftoken',
        'email',
        'adresse',
        'identifiant',
        'referer',
    ];

    /**
     * Les clefs dont la valeur est un document JSON écrit en texte, à lire
     * pour le masquer : l'instantané d'un composant Livewire
     * (`components.*.snapshot`), qui porte tout l'état de son formulaire.
     *
     * @var list<string>
     */
    private const array CLEFS_EN_JSON = ['snapshot'];

    protected static function booted(): void
    {
        self::creating(function (self $exception): void {
            $exception->user_id ??= self::compteDeLaRequete();
        });

        self::saving(function (self $exception): void {
            $exception->cookies = null;

            /** @var array<string, mixed>|null $corps */
            $corps = self::bornerLeJson(self::masquer($exception->body), self::OCTETS_MAX_PAR_COLONNE['body']);
            $exception->body = $corps;

            /** @var array<string, array<int, string>|string> $entetes */
            $entetes = self::bornerLeJson(self::masquer($exception->headers), self::OCTETS_MAX_PAR_COLONNE['headers']) ?? [];
            $exception->headers = $entetes;

            /** @var array<int, array<string, mixed>> $pile */
            $pile = self::bornerLeJson($exception->trace, self::OCTETS_MAX_PAR_COLONNE['trace']) ?? [];
            $exception->trace = $pile;

            /** @var array<int, array<string, mixed>> $requetes */
            $requetes = self::bornerLeJson($exception->query, self::OCTETS_MAX_PAR_COLONNE['query']) ?? [];
            $exception->query = $requetes;

            /** @var array<string, string>|null $contexte */
            $contexte = self::bornerLeJson($exception->route_context, self::OCTETS_MAX_PAR_COLONNE['route_context']);
            $exception->route_context = $contexte;

            if (is_string($exception->getAttribute('path'))) {
                $exception->path = self::masquerLeChemin($exception->path, $exception->route_parameters);
            }

            /** @var array<string, mixed>|null $parametres */
            $parametres = self::bornerLeJson(self::masquer($exception->route_parameters), self::OCTETS_MAX_PAR_COLONNE['route_parameters']);
            $exception->route_parameters = $parametres;

            $exception->message = self::couper(TexteSansAdresse::de((string) $exception->message), self::OCTETS_MAX_PAR_COLONNE['message']);
            $exception->markdown = $exception->markdown === null
                ? null
                : self::couper(TexteSansAdresse::de($exception->markdown), self::OCTETS_MAX_PAR_COLONNE['markdown']);

            foreach (self::CARACTERES_MAX_PAR_COLONNE as $colonne => $caracteres) {
                $valeur = $exception->getAttribute($colonne);

                if (is_string($valeur) && mb_strlen($valeur) > $caracteres) {
                    $exception->setAttribute($colonne, mb_substr($valeur, 0, $caracteres));
                }
            }
        });
    }

    /**
     * L'identifiant du compte que la requête a déjà authentifié, sur la garde
     * `web`, celle des comptes : les pages et, par Sanctum, l'API.
     *
     * Jamais la garde par défaut (`Auth::id()`) : le panneau en fait la garde
     * `admin`, et un administrateur porte souvent l'identifiant d'un compte,
     * qui hériterait de ses exceptions et les emporterait. `hasUser()` et non
     * `user()` : `user()` lirait la session ou le cookie « se souvenir de
     * moi », connecterait un compte au milieu du rapport d'une exception, et
     * rattacherait au compte d'une session voisine une exception qu'aucune de
     * ses requêtes n'a levée. Un compte que la requête vient de supprimer
     * (`exists` faux) n'en reçoit pas : la clé refuserait la ligne, et le
     * paquet perdrait l'exception sans rien dire.
     */
    private static function compteDeLaRequete(): ?int
    {
        $garde = Auth::guard('web');
        $compte = $garde->hasUser() ? $garde->user() : null;

        return $compte instanceof User && $compte->exists ? $compte->id : null;
    }

    /**
     * Les valeurs, à toute profondeur, dont la clef est sensible remplacées
     * par « [masqué] », et chaque instantané JSON lu et masqué de même.
     *
     * @param  array<mixed>|null  $valeurs
     * @return array<mixed>|null
     */
    private static function masquer(?array $valeurs): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        foreach ($valeurs as $clef => $valeur) {
            $forme = self::formeComparable($clef);

            if (in_array($forme, self::CLEFS_MASQUEES, true)) {
                $valeurs[$clef] = '[masqué]';
            } elseif (is_array($valeur)) {
                $valeurs[$clef] = self::masquer($valeur);
            } elseif (is_string($valeur) && in_array($forme, self::CLEFS_EN_JSON, true)) {
                $valeurs[$clef] = self::masquerLeJson($valeur);
            }
        }

        return $valeurs;
    }

    /**
     * Le chemin de la requête, où chaque segment qui porte la valeur d'un
     * paramètre de route masqué devient « [masqué] » : le jeton du lien de
     * réinitialisation est un segment de son chemin (`reset-password/{token}`),
     * que le paquet garde tel quel (`request()->path()`). Un paramètre occupe
     * un segment, le motif par défaut de Laravel n'admettant pas `/` ; le
     * chemin garde son encodage, la valeur du paramètre est décodée.
     *
     * @param  array<mixed>|null  $parametres
     */
    private static function masquerLeChemin(string $chemin, ?array $parametres): string
    {
        $valeursMasquees = [];

        foreach ($parametres ?? [] as $clef => $valeur) {
            if ((is_string($valeur) || is_int($valeur)) && $valeur !== '' && in_array(self::formeComparable($clef), self::CLEFS_MASQUEES, true)) {
                $valeursMasquees[] = (string) $valeur;
            }
        }

        return implode('/', array_map(
            static fn (string $segment): string => in_array(rawurldecode($segment), $valeursMasquees, true) ? '[masqué]' : $segment,
            explode('/', $chemin),
        ));
    }

    /**
     * La forme sous laquelle une clef se compare à `CLEFS_MASQUEES` : le
     * dernier segment d'un chemin à points (`data.password` de Livewire), en
     * minuscules, sans `_` ni `-`. Une clef de liste n'a pas de forme.
     */
    private static function formeComparable(int|string $clef): ?string
    {
        if (is_int($clef)) {
            return null;
        }

        return str_replace(['_', '-'], '', strtolower(Str::afterLast($clef, '.')));
    }

    /**
     * Un document JSON écrit en texte, masqué comme un tableau puis réécrit.
     * Un texte qui ne se lit pas comme un objet ou une liste JSON n'est pas
     * gardé : rien ne dit ce qu'il porte.
     */
    private static function masquerLeJson(string $texte): string
    {
        $document = json_decode($texte, true);

        if (! is_array($document)) {
            return '[masqué]';
        }

        return (string) json_encode(self::masquer($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Un tableau qui tient dans `$octets` une fois écrit en JSON.
     *
     * Le parcours garde les premiers éléments tant que le budget le permet, en
     * comptant chaque valeur telle que JSON l'écrit, échappements compris. Le
     * résultat est mesuré quand même, et le parcours recommence avec un budget
     * réduit d'un quart tant qu'il déborde.
     *
     * @param  array<mixed>|null  $valeurs
     * @return array<mixed>|null
     */
    private static function bornerLeJson(?array $valeurs, int $octets): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        for ($budget = $octets; $budget >= 64; $budget = intdiv($budget * 3, 4)) {
            $reste = $budget;
            $bornees = self::borner($valeurs, $reste, 0);
            $json = json_encode($bornees);

            if ($json === false || strlen($json) <= $octets) {
                return $bornees;
            }
        }

        return [];
    }

    /**
     * Les premiers éléments d'un tableau, chacun borné, tant que `$reste`
     * octets de JSON le permettent.
     *
     * Dans un objet, une valeur imbriquée ne prend que la moitié de ce qui
     * reste : un champ démesuré ne prive pas ceux qui le suivent (un mot de
     * passe masqué reste visible comme tel). Dans une liste, chaque élément
     * prend ce qui reste, dans l'ordre : le haut de la pile d'abord.
     *
     * @param  array<mixed>  $valeurs
     * @return array<mixed>
     */
    private static function borner(array $valeurs, int &$reste, int $profondeur): array
    {
        $estUneListe = array_is_list($valeurs);
        $bornees = [];
        $gardes = 0;
        $reste -= 2;

        foreach ($valeurs as $clef => $valeur) {
            if ($reste <= 0) {
                break;
            }

            $clef = is_string($clef) ? self::couper($clef, self::OCTETS_MAX_PAR_CLE) : $clef;
            $reste -= ($estUneListe ? 0 : self::longueurEnJson((string) $clef) + 1) + 1;

            if (is_object($valeur)) {
                $valeur = json_decode((string) json_encode($valeur), true);
            }

            if (is_array($valeur) && $profondeur < self::PROFONDEUR_MAX) {
                $part = $estUneListe ? $reste : min($reste, max(256, intdiv($reste, 2)));
                $partAccordee = $part;
                $bornees[$clef] = self::borner($valeur, $part, $profondeur + 1);
                $reste -= $partAccordee - $part;
            } elseif (is_array($valeur)) {
                $bornees[$clef] = '[tronqué]';
                $reste -= 13;
            } else {
                $bornees[$clef] = is_string($valeur)
                    ? self::couper($valeur, max(16, min(self::OCTETS_MAX_PAR_VALEUR, $reste)))
                    : $valeur;
                $reste -= self::longueurEnJson($bornees[$clef]);
            }

            $gardes++;
        }

        if (! $estUneListe && $gardes < count($valeurs)) {
            $bornees['…'] = sprintf('[tronqué : %d élément(s) non gardé(s)]', count($valeurs) - $gardes);
        }

        return $bornees;
    }

    /**
     * La longueur d'une valeur écrite en JSON, comme Laravel l'écrit.
     */
    private static function longueurEnJson(mixed $valeur): int
    {
        return strlen((string) json_encode($valeur));
    }

    /**
     * Un texte coupé à `$octets` octets au plus, sur une limite de caractère,
     * et qui dit qu'il l'a été.
     */
    private static function couper(string $texte, int $octets): string
    {
        if (strlen($texte) <= $octets) {
            return $texte;
        }

        $marque = '… [tronqué]';

        return mb_strcut($texte, 0, max(0, $octets - strlen($marque)), 'UTF-8').$marque;
    }
}
