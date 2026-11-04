<?php

declare(strict_types=1);

use Filament\Forms\Components\Concerns\CanBeValidated;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Numeric;
use Symfony\Component\Finder\Finder;
use Tests\Support\ReglesDesRequetes;

/*
 * Une comparaison entre deux champs numériques passe par un objet de règle qui
 * compare des nombres.
 *
 * La cible d'un objectif se compare à son départ par
 * `App\Rules\CibleAuMoinsEgaleAuDepart` (#2164), que la requête de
 * l'application et le formulaire du panneau partagent : la comparaison s'y lit
 * en une ligne, en nombres, et ne s'écrit qu'une fois. Une règle qui nomme
 * l'autre champ n'a pas cours : `gt`, `gte`, `lt` et `lte` suivis d'un nom de
 * champ, `Rule::numeric()->greaterThan()` et ses voisines, et les méthodes de
 * comparaison des champs de Filament (`gt()`, `gte()`, `lt()`, `lte()`,
 * `fieldComparisonRule()`, `multiFieldComparisonRule()`). Une borne (`min`,
 * `max`, `between`, `size`, `multiple_of`, leurs méthodes de
 * `Rule::numeric()`, et au panneau `minValue()`, `maxValue()` et leurs
 * voisines) dont la valeur viendrait d'un autre champ compare elle aussi deux
 * champs : elle ne prend qu'une valeur fixe. La décision est dans
 * `.ai/rules/requests.md`. Un paramètre numérique (`gte:0`), écrit en chiffres
 * ou tenu par une constante (`'lte:'.Goal::VALEUR_MAX`), ne compare pas deux
 * champs et reste permis.
 *
 * Deux lectures, comme `AucuneRequeteNeDitQuUneAdresseAUnCompteTest`. Les
 * règles de chaque requête, comme Laravel les lit : listes `a|b`,
 * `Rule::when()` déplié de ses deux côtés, objets qui s'écrivent en texte, et
 * tableau imbriqué lu comme une règle, `[nom, paramètres…]`. Et les jetons PHP
 * de tout app/, qui voient aussi une validation écrite au contrôleur, dans une
 * action ou au panneau : chaque chaîne, à quelque place qu'elle soit, même là
 * où Laravel ne l'appliquerait pas, chaque tableau littéral `[nom, paramètre]`,
 * et les chaînes d'appels sur un champ du panneau ou sur `Rule::numeric()`,
 * qu'ils soient rangés dans une variable ou reçus en paramètre typé. La
 * lecture des jetons ne connaît que les écritures de ses exemples : une autre
 * lui échappe, et ne vaut pas permission.
 */

/**
 * Les règles de comparaison qui nomment l'autre champ quand leur paramètre
 * n'est pas un nombre.
 */
const DEUX_CHAMPS_REGLES = ['gt', 'gte', 'lt', 'lte'];

/**
 * Les règles dont chaque paramètre est une borne fixe : un paramètre tiré
 * d'un autre champ (`'min:'.$this->input('start_value')`) compare lui aussi
 * deux champs. Les noms sont lus comme Laravel les lit (`multiple_of`
 * devient `multipleof`).
 */
const DEUX_CHAMPS_REGLES_DE_BORNE = ['min', 'max', 'between', 'size', 'multipleof'];

/**
 * Les méthodes des champs de Filament qui posent une règle de comparaison.
 */
const DEUX_CHAMPS_METHODES_DU_PANNEAU = ['gt', 'gte', 'lt', 'lte', 'fieldcomparisonrule', 'multifieldcomparisonrule'];

/**
 * Les méthodes des champs de Filament qui posent une règle de borne de leurs
 * arguments (`minValue(0)` pose `min:0`, `maxLength(255)` pose `max:255`).
 */
const DEUX_CHAMPS_BORNES_DU_PANNEAU = ['minvalue', 'maxvalue', 'range', 'multipleof', 'length', 'minlength', 'maxlength', 'minitems', 'maxitems', 'minsize', 'maxsize', 'minfiles', 'maxfiles'];

/**
 * Les méthodes de `Rule::numeric()` qui posent une règle de comparaison.
 */
const DEUX_CHAMPS_METHODES_DE_NUMERIC = ['greaterthan', 'greaterthanorequalto', 'lessthan', 'lessthanorequalto'];

/**
 * Les méthodes de `Rule::numeric()` qui posent une règle de borne.
 */
const DEUX_CHAMPS_BORNES_DE_NUMERIC = ['min', 'max', 'between', 'exactly', 'multipleof'];

/**
 * Ces valeurs sont-elles toutes des nombres ? `\0`, une valeur qu'on ne
 * connaît pas, n'en est pas un.
 *
 * @param  array<array-key, mixed>  $valeurs
 */
function deuxChampsSontDesNombres(array $valeurs): bool
{
    return array_filter($valeurs, static fn (mixed $valeur): bool => ! is_numeric($valeur)) === [];
}

/**
 * Cette règle écrite en texte compare-t-elle le champ à un autre ?
 *
 * Le nom se lit comme Laravel le lit (`ValidationRuleParser::parseStringRule()`) :
 * coupé de ses blancs puis mis en StudlyCase, ce qui rend « GTE », « gte » et
 * « g_te » à la même méthode. Le premier paramètre d'une comparaison est un
 * nombre, ou il nomme un champ ; chaque paramètre d'une borne est un nombre,
 * ou il vient d'ailleurs, d'un autre champ peut-être.
 */
function deuxChampsRegleCompareDeuxChamps(string $regle): bool
{
    if (! str_contains($regle, ':')) {
        return false;
    }

    [$nom, $parametres] = explode(':', $regle, 2);
    $nom = strtolower(Str::studly(trim($nom)));
    $valeurs = str_getcsv($parametres, escape: '\\');

    return match (true) {
        in_array($nom, DEUX_CHAMPS_REGLES, true) => ! is_numeric($valeurs[0] ?? ''),
        in_array($nom, DEUX_CHAMPS_REGLES_DE_BORNE, true) => ! deuxChampsSontDesNombres($valeurs),
        default => false,
    };
}

/**
 * Un paramètre prêt à joindre aux autres par des virgules : cité quand il
 * porte une virgule ou un guillemet, pour que la lecture du texte le rende
 * entier.
 */
function deuxChampsParametreCite(string $parametre): string
{
    return strpbrk($parametre, ',"') === false ? $parametre : '"'.str_replace('"', '""', $parametre).'"';
}

/**
 * Une règle écrite en tableau, en texte, comme Laravel la lit
 * (`ValidationRuleParser::parseArrayRule()`) : le premier élément est son nom,
 * les suivants ses paramètres, recomposés en « nom:p1,p2 ». Un paramètre qui
 * n'est ni un nombre ni un texte vaut `\0`, une valeur qu'on ne connaît pas.
 * Un nom qui n'est pas un texte, ou qui porte lui-même des deux-points, ne
 * nomme aucune règle que Laravel applique.
 *
 * @param  array<array-key, mixed>  $regle
 */
function deuxChampsRegleEnTableau(array $regle): string
{
    $nom = $regle[0] ?? '';

    if (! is_string($nom) || str_contains($nom, ':')) {
        return '';
    }

    $parametres = array_map(
        static fn (mixed $parametre): string => deuxChampsParametreCite(is_int($parametre) || is_float($parametre) || is_string($parametre) ? (string) $parametre : "\0"),
        array_slice($regle, 1),
    );

    return $parametres === [] ? $nom : $nom.':'.implode(',', $parametres);
}

/**
 * Les règles d'un champ en texte, lues comme Laravel les lit
 * (`ValidationRuleParser`) : une chaîne découpée à `|`, `Rule::when()` déplié
 * de ses deux côtés, un objet qui s'écrit en texte découpé de même, et un
 * tableau imbriqué lu comme une seule règle (`deuxChampsRegleEnTableau()`),
 * jamais comme une liste de règles.
 *
 * @param  array<array-key, mixed>  $regles  la liste des règles d'un champ
 * @return list<string>
 */
function deuxChampsReglesEnTexte(array $regles): array
{
    $textes = [];

    foreach ($regles as $regle) {
        if ($regle instanceof ConditionalRules) {
            array_push($textes, ...deuxChampsReglesEnTexte([...$regle->rules(), ...$regle->defaultRules()]));
        } elseif (is_array($regle)) {
            $textes[] = deuxChampsRegleEnTableau($regle);
        } elseif (is_string($regle)) {
            array_push($textes, ...explode('|', $regle));
        } elseif ($regle instanceof Stringable) {
            array_push($textes, ...ReglesDesRequetes::textes([$regle]));
        }
    }

    return $textes;
}

/**
 * Un texte lu dans le code, lisible dans un message : `\0` y devient « … ».
 */
function deuxChampsLisible(string $texte): string
{
    return str_replace("\0", '…', $texte);
}

/**
 * Les jetons d'un code PHP, sans blancs ni commentaires.
 *
 * @return list<PhpToken>
 */
function deuxChampsJetons(string $code): array
{
    return array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $jeton): bool => ! $jeton->isIgnorable()));
}

/**
 * Le texte d'une chaîne littérale, sans ses guillemets.
 */
function deuxChampsTexteLitteral(PhpToken $jeton): string
{
    $brut = $jeton->text;
    $guillemet = $brut[0] ?? '';

    if ($guillemet === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], substr($brut, 1, -1));
    }

    return stripcslashes(substr($brut, 1, -1));
}

/**
 * Lit, à partir de l'indice donné, une chaîne : littérale, ou interpolée
 * (`"…{$x}…"`, heredoc), dont chaque morceau qui n'est pas du texte devient
 * `\0`, une valeur qu'on ne connaît pas.
 *
 * @param  list<PhpToken>  $jetons
 * @return array{0: string, 1: int}|null le texte et l'indice du dernier jeton lu
 */
function deuxChampsChaineA(array $jetons, int $indice): ?array
{
    $jeton = $jetons[$indice];

    if ($jeton->is(T_CONSTANT_ENCAPSED_STRING)) {
        return [deuxChampsTexteLitteral($jeton), $indice];
    }

    $fin = match (true) {
        $jeton->text === '"' => '"',
        $jeton->is(T_START_HEREDOC) => T_END_HEREDOC,
        default => null,
    };

    if ($fin === null) {
        return null;
    }

    $texte = '';
    $dernier = $indice;

    for ($position = $indice + 1, $nombre = count($jetons); $position < $nombre; $position++) {
        $morceau = $jetons[$position];
        $dernier = $position;

        if (is_string($fin) ? $morceau->text === $fin : $morceau->is($fin)) {
            break;
        }

        if ($morceau->is(T_ENCAPSED_AND_WHITESPACE)) {
            $texte .= $morceau->text;
        } elseif (! str_ends_with($texte, "\0")) {
            $texte .= "\0";
        }
    }

    return [$texte, $dernier];
}

/**
 * L'indice du dernier jeton d'une constante de classe qui commence à l'indice
 * donné (`Goal::VALEUR_MAX`, `self::CLEF_MAX`, `Goal::class`), ou null : un
 * appel (`Goal::make(…)`), une case d'énumération lue plus loin (`->value`) ou
 * une case de tableau n'en sont pas.
 *
 * @param  list<PhpToken>  $jetons
 */
function deuxChampsFinDUneConstante(array $jetons, int $indice): ?int
{
    $estUneConstante = $jetons[$indice]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])
        && ($jetons[$indice + 1]->text ?? '') === '::'
        && ($jetons[$indice + 2] ?? null)?->is([T_STRING, T_CLASS]) === true
        && ! in_array($jetons[$indice + 3]->text ?? '', ['(', '[', '->', '?->', '::'], true);

    return $estUneConstante ? $indice + 2 : null;
}

/**
 * La classe dont hérite la classe donnée, ou null.
 */
function deuxChampsClasseParente(?string $classe): ?string
{
    $parente = $classe !== null && class_exists($classe) ? get_parent_class($classe) : false;

    return $parente === false ? null : $parente;
}

/**
 * La valeur d'une constante de classe, en texte, telle qu'une concaténation
 * l'écrirait : la classe est résolue par la portée du fichier (`self`,
 * `static` et `parent` par la classe qu'il déclare), la constante lue par
 * réflexion, privée comprise. Null quand la classe ou la constante sont
 * introuvables, ou quand elle ne tient ni nombre ni texte.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 */
function deuxChampsValeurDUneConstante(array $jetons, int $indice, array $portee): ?string
{
    $classe = match (strtolower($jetons[$indice]->text)) {
        'self', 'static' => $portee['classe'],
        'parent' => deuxChampsClasseParente($portee['classe']),
        default => deuxChampsClasseNommee($jetons[$indice], $portee),
    };

    if ($classe === null) {
        return null;
    }

    if ($jetons[$indice + 2]->is(T_CLASS)) {
        return $classe;
    }

    if (! class_exists($classe) && ! interface_exists($classe) && ! trait_exists($classe)) {
        return null;
    }

    $reflexion = new ReflectionClass($classe);
    $nom = $jetons[$indice + 2]->text;
    $valeur = $reflexion->hasConstant($nom) ? $reflexion->getConstant($nom) : null;

    return is_int($valeur) || is_float($valeur) || is_string($valeur) ? (string) $valeur : null;
}

/**
 * Lit, à partir de l'indice donné, un opérande d'une concaténation : une
 * chaîne (`deuxChampsChaineA()`), un nombre écrit en chiffres, ou une
 * constante de classe, qui vaut `\0` quand on ne la retrouve pas.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 * @return array{0: string, 1: int}|null la valeur et l'indice du dernier jeton lu
 */
function deuxChampsOperandeA(array $jetons, int $indice, array $portee): ?array
{
    $chaine = deuxChampsChaineA($jetons, $indice);

    if ($chaine !== null) {
        return $chaine;
    }

    $jeton = $jetons[$indice];

    if ($jeton->is([T_LNUMBER, T_DNUMBER])) {
        $nombre = str_replace('_', '', $jeton->text);

        return [is_numeric($nombre) ? $nombre : "\0", $indice];
    }

    $fin = deuxChampsFinDUneConstante($jetons, $indice);

    return $fin === null ? null : [deuxChampsValeurDUneConstante($jetons, $indice, $portee) ?? "\0", $fin];
}

/**
 * Les textes que le code assemble : chaque chaîne, jointe à ce qu'on lui
 * concatène (chaînes, nombres, constantes de classe retrouvées), une valeur
 * concaténée qu'on ne connaît pas valant `\0`. Une concaténation peut
 * commencer par un nombre ou une constante ; seuls, ils ne sont pas des
 * textes.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 * @return list<array{texte: string, ligne: int, debut: int, fin: int}>
 */
function deuxChampsTextesAssembles(array $jetons, array $portee): array
{
    $textes = [];
    $lu = -1;

    foreach ($jetons as $indice => $jeton) {
        if ($indice <= $lu) {
            continue;
        }

        $finDUneValeur = $jeton->is([T_LNUMBER, T_DNUMBER]) ? $indice : deuxChampsFinDUneConstante($jetons, $indice);

        if ($finDUneValeur !== null && ($jetons[$finDUneValeur + 1]->text ?? '') !== '.') {
            continue;
        }

        $operande = deuxChampsOperandeA($jetons, $indice, $portee);

        if ($operande === null) {
            continue;
        }

        [$texte, $lu] = $operande;

        if ($indice > 0 && $jetons[$indice - 1]->text === '.') {
            $texte = "\0".$texte;
        }

        while (($jetons[$lu + 1]->text ?? '') === '.') {
            $suivant = isset($jetons[$lu + 2]) ? deuxChampsOperandeA($jetons, $lu + 2, $portee) : null;

            if ($suivant === null) {
                $texte .= "\0";
                break;
            }

            [$morceau, $lu] = $suivant;
            $texte .= $morceau;
        }

        $textes[] = ['texte' => $texte, 'ligne' => $jeton->line, 'debut' => $indice, 'fin' => $lu];
    }

    return $textes;
}

/**
 * La portée d'un fichier : son espace de noms, les noms courts qu'il importe,
 * vers leur nom complet, et la classe qu'il déclare. `use A\B;`,
 * `use A\B as C;` et `use A\{B, C as D};` ; une fermeture (`use ($x)`), une
 * fonction ou une constante importées n'y entrent pas.
 *
 * @param  list<PhpToken>  $jetons
 * @return array{espace: string, imports: array<string, string>, classe: string|null}
 */
function deuxChampsPortee(array $jetons): array
{
    $espace = '';
    $imports = [];
    $classe = null;
    $noms = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    foreach ($jetons as $indice => $jeton) {
        $suivant = $jetons[$indice + 1] ?? null;

        if ($suivant === null || ! $suivant->is($noms)) {
            continue;
        }

        if ($jeton->is(T_NAMESPACE)) {
            $espace = ltrim($suivant->text, '\\');

            continue;
        }

        if ($classe === null && $jeton->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ($jetons[$indice - 1]->text ?? '') !== '::') {
            $classe = ltrim($espace.'\\'.$suivant->text, '\\');

            continue;
        }

        if (! $jeton->is(T_USE)) {
            continue;
        }

        $prefixe = ltrim($suivant->text, '\\');
        $groupe = ($jetons[$indice + 2] ?? null)?->is(T_NS_SEPARATOR) === true;
        $dernier = null;

        foreach (array_slice($jetons, $groupe ? $indice + 3 : $indice + 1, null, true) as $position => $morceau) {
            if ($morceau->text === ';' || $morceau->text === '}') {
                break;
            }

            if (! $morceau->is($noms)) {
                continue;
            }

            if ($dernier !== null && $jetons[$position - 1]->is(T_AS)) {
                unset($imports[strtolower(class_basename($dernier))]);
                $imports[strtolower($morceau->text)] = $dernier;

                continue;
            }

            $dernier = $groupe ? $prefixe.'\\'.$morceau->text : ltrim($morceau->text, '\\');
            $imports[strtolower(class_basename($dernier))] = $dernier;
        }
    }

    return ['espace' => $espace, 'imports' => $imports, 'classe' => $classe];
}

/**
 * Le nom complet de la classe qu'un jeton nomme dans ce fichier.
 *
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 */
function deuxChampsClasseNommee(PhpToken $jeton, array $portee): string
{
    if ($jeton->is(T_NAME_FULLY_QUALIFIED)) {
        return ltrim($jeton->text, '\\');
    }

    $segments = explode('\\', $jeton->text);
    $tete = strtolower($segments[0]);

    if (isset($portee['imports'][$tete])) {
        return implode('\\', [$portee['imports'][$tete], ...array_slice($segments, 1)]);
    }

    return ltrim($portee['espace'].'\\'.$jeton->text, '\\');
}

/**
 * La classe est-elle un champ de Filament, qui porte les méthodes de
 * validation (`CanBeValidated`) ?
 */
function deuxChampsEstUnChampDuPanneau(string $classe): bool
{
    return class_exists($classe) && in_array(CanBeValidated::class, class_uses_recursive($classe), true);
}

/**
 * L'indice de la parenthèse, du crochet ou de l'accolade qui ferme celle que
 * l'indice donné ouvre.
 *
 * @param  list<PhpToken>  $jetons
 */
function deuxChampsFermante(array $jetons, int $ouvrante): int
{
    $profondeur = 0;

    for ($indice = $ouvrante, $nombre = count($jetons); $indice < $nombre; $indice++) {
        $jeton = $jetons[$indice];
        $profondeur += match (true) {
            in_array($jeton->text, ['(', '[', '{'], true), $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
            in_array($jeton->text, [')', ']', '}'], true) => -1,
            default => 0,
        };

        if ($profondeur === 0) {
            return $indice;
        }
    }

    return array_key_last($jetons) ?? $ouvrante;
}

/**
 * Les éléments d'une liste que l'indice donné ouvre (les éléments d'un
 * tableau, les arguments d'un appel) : l'indice du premier et du dernier
 * jeton de chacun, aux virgules du premier niveau.
 *
 * @param  list<PhpToken>  $jetons
 * @return list<array{0: int, 1: int}>
 */
function deuxChampsElements(array $jetons, int $ouvrante): array
{
    $fermante = deuxChampsFermante($jetons, $ouvrante);
    $elements = [];
    $debut = $ouvrante + 1;

    for ($indice = $debut; $indice < $fermante; $indice++) {
        $jeton = $jetons[$indice];

        if (in_array($jeton->text, ['(', '[', '{'], true) || $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $indice = deuxChampsFermante($jetons, $indice);
        } elseif ($jeton->text === ',') {
            $elements[] = [$debut, $indice - 1];
            $debut = $indice + 1;
        }
    }

    if ($debut < $fermante) {
        $elements[] = [$debut, $fermante - 1];
    }

    return $elements;
}

/**
 * La valeur, en texte, de l'expression qui va du premier au dernier jeton
 * donnés, quand le code la dit : une chaîne, un nombre (signé ou non), une
 * constante retrouvée, ou leur concaténation. `\0` sinon.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 */
function deuxChampsValeurDeLExpression(array $jetons, int $debut, int $fin, array $portee): string
{
    $signe = '';

    if ($debut < $fin && in_array($jetons[$debut]->text, ['-', '+'], true)) {
        $signe = $jetons[$debut]->text === '-' ? '-' : '';
        $debut++;
    }

    $operande = deuxChampsOperandeA($jetons, $debut, $portee);

    if ($operande === null) {
        return "\0";
    }

    [$texte, $lu] = $operande;

    while ($lu + 2 <= $fin && $jetons[$lu + 1]->text === '.') {
        $suivant = deuxChampsOperandeA($jetons, $lu + 2, $portee);

        if ($suivant === null) {
            return "\0";
        }

        [$morceau, $lu] = $suivant;
        $texte .= $morceau;
    }

    return $lu === $fin ? $signe.$texte : "\0";
}

/**
 * La valeur d'un argument d'appel, nommé ou non (`value: 5`).
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{0: int, 1: int}  $element
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 */
function deuxChampsValeurDUnArgument(array $jetons, array $element, array $portee): string
{
    [$debut, $fin] = $element;

    if ($debut + 1 < $fin && $jetons[$debut]->is(T_STRING) && $jetons[$debut + 1]->text === ':') {
        $debut += 2;
    }

    return deuxChampsValeurDeLExpression($jetons, $debut, $fin, $portee);
}

/**
 * Les règles que le code écrit en tableau littéral (`['gte', 'start_value']`,
 * `array('gte', $champ)`), recomposées en texte : un tableau dont le premier
 * élément est un texte qui nomme une comparaison ou une borne, suivi d'autres
 * éléments. Chaque paramètre vaut sa valeur quand le code la dit, `\0` sinon.
 *
 * @param  list<PhpToken>  $jetons
 * @param  list<array{texte: string, ligne: int, debut: int, fin: int}>  $textes
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 * @return list<array{regle: string, ligne: int}>
 */
function deuxChampsReglesEnTableauDuCode(array $jetons, array $textes, array $portee): array
{
    $regles = [];
    $noms = [...DEUX_CHAMPS_REGLES, ...DEUX_CHAMPS_REGLES_DE_BORNE];

    foreach ($textes as ['texte' => $texte, 'ligne' => $ligne, 'debut' => $debut, 'fin' => $fin]) {
        $ouvrante = $debut - 1;
        $ouvreUnTableau = ($jetons[$ouvrante]->text ?? '') === '['
            || (($jetons[$ouvrante]->text ?? '') === '(' && ($jetons[$ouvrante - 1] ?? null)?->is(T_ARRAY) === true);

        if (! $ouvreUnTableau || ($jetons[$fin + 1]->text ?? '') !== ',' || ! in_array(strtolower(Str::studly(trim($texte))), $noms, true)) {
            continue;
        }

        $parametres = array_map(
            static fn (array $element): string => deuxChampsValeurDeLExpression($jetons, $element[0], $element[1], $portee),
            array_slice(deuxChampsElements($jetons, $ouvrante), 1),
        );

        $regles[] = ['regle' => deuxChampsRegleEnTableau([$texte, ...$parametres]), 'ligne' => $ligne];
    }

    return $regles;
}

/**
 * La parenthèse que l'indice donné ouvre ouvre-t-elle un appel (`f(…)`,
 * `$f(…)`, `isset(…)`) plutôt qu'un groupe (`(new Numeric)`) ?
 *
 * @param  list<PhpToken>  $jetons
 */
function deuxChampsOuvreUnAppel(array $jetons, int $ouvrante): bool
{
    $precedent = $jetons[$ouvrante - 1] ?? null;

    return $precedent !== null && ($precedent->is([
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE, T_ARRAY, T_LIST, T_ISSET, T_UNSET,
        T_EMPTY, T_EVAL, T_EXIT, T_STATIC, T_CLASS, T_FN, T_FUNCTION, T_USE, T_DECLARE,
    ]) || in_array($precedent->text, [')', ']', '}'], true));
}

/**
 * Le début et la fin d'une expression sortie des parenthèses qui la
 * groupent : `(new Numeric)->…` et `(TextInput::make('a'))->…` portent leurs
 * appels après la parenthèse fermante, et `$regle = (new Numeric);` range
 * l'objet dans la variable qui précède la parenthèse ouvrante.
 *
 * @param  list<PhpToken>  $jetons
 * @return array{0: int, 1: int}
 */
function deuxChampsHorsDesParentheses(array $jetons, int $debut, int $fin): array
{
    while (($jetons[$debut - 1]->text ?? '') === '(' && ($jetons[$fin + 1]->text ?? '') === ')'
        && ! deuxChampsOuvreUnAppel($jetons, $debut - 1)) {
        $debut--;
        $fin++;
    }

    return [$debut, $fin];
}

/**
 * La sorte d'une classe nommée dans le code : `champ` pour un champ du
 * panneau, `numeric` pour `Numeric`, la règle que rend `Rule::numeric()`,
 * null pour toute autre.
 */
function deuxChampsSorteDeLaClasse(string $classe): ?string
{
    return match (true) {
        deuxChampsEstUnChampDuPanneau($classe) => 'champ',
        $classe === Numeric::class => 'numeric',
        default => null,
    };
}

/**
 * Les appels chaînés après le jeton donné, la fin de ce qui les reçoit (une
 * parenthèse fermante, une variable) : le nom de chaque méthode, en
 * minuscules, et la valeur de chacun de ses arguments
 * (`deuxChampsValeurDeLExpression()`).
 *
 * @param  list<PhpToken>  $jetons
 * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $portee
 * @return list<array{methode: string, arguments: list<string>, ligne: int}>
 */
function deuxChampsChaineDAppels(array $jetons, int $receveur, array $portee): array
{
    $appels = [];
    $fin = $receveur;

    while (($jetons[$fin + 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true
        && ($jetons[$fin + 2] ?? null)?->is(T_STRING) === true
        && ($jetons[$fin + 3]->text ?? '') === '(') {
        $arguments = array_map(
            static fn (array $element): string => deuxChampsValeurDUnArgument($jetons, $element, $portee),
            deuxChampsElements($jetons, $fin + 3),
        );
        $appels[] = ['methode' => strtolower($jetons[$fin + 2]->text), 'arguments' => $arguments, 'ligne' => $jetons[$fin + 2]->line];
        $fin = deuxChampsFermante($jetons, $fin + 3);
    }

    return $appels;
}

/**
 * Cet appel d'une chaîne compare-t-il deux champs ? Sur un champ du panneau,
 * une méthode de comparaison toujours, une méthode de borne quand un argument
 * n'est pas un nombre ; sur `Rule::numeric()`, une comparaison vers ce qui
 * n'est pas un nombre, une borne de même.
 *
 * @param  list<string>  $arguments
 */
function deuxChampsAppelCompareDeuxChamps(string $sorte, string $methode, array $arguments): bool
{
    if ($sorte === 'numeric') {
        return (in_array($methode, DEUX_CHAMPS_METHODES_DE_NUMERIC, true) && ! is_numeric($arguments[0] ?? ''))
            || (in_array($methode, DEUX_CHAMPS_BORNES_DE_NUMERIC, true) && ! deuxChampsSontDesNombres($arguments));
    }

    return in_array($methode, DEUX_CHAMPS_METHODES_DU_PANNEAU, true)
        || (in_array($methode, DEUX_CHAMPS_BORNES_DU_PANNEAU, true) && ! deuxChampsSontDesNombres($arguments));
}

/**
 * Ce qu'un code PHP écrit qui compare deux champs autrement que par un objet
 * de règle, et le nombre de champs du panneau qu'il construit.
 *
 * @return array{fautes: list<string>, champs: int}
 */
function deuxChampsLireLeCode(string $code): array
{
    $jetons = deuxChampsJetons($code);
    $portee = deuxChampsPortee($jetons);
    $textes = deuxChampsTextesAssembles($jetons, $portee);
    $fautes = [];
    $champs = 0;

    foreach ($textes as ['texte' => $texte, 'ligne' => $ligne]) {
        foreach (explode('|', $texte) as $regle) {
            if (deuxChampsRegleCompareDeuxChamps($regle)) {
                $fautes[] = "ligne {$ligne} : règle « ".deuxChampsLisible(trim($regle, " \t\n\r")).' »';
            }
        }
    }

    foreach (deuxChampsReglesEnTableauDuCode($jetons, $textes, $portee) as ['regle' => $regle, 'ligne' => $ligne]) {
        if (deuxChampsRegleCompareDeuxChamps($regle)) {
            $fautes[] = "ligne {$ligne} : règle en tableau « ".deuxChampsLisible($regle).' »';
        }
    }

    /** @var array<string, string> $variables la sorte de ce que chaque variable porte */
    $variables = [];

    foreach ($jetons as $indice => $jeton) {
        $suivant = $jetons[$indice + 1] ?? null;

        if ($suivant === null) {
            continue;
        }

        // Un paramètre typé : `fn (TextInput $champ) => …`, `fn (Numeric $regle) => …`.
        $sorteDuParametre = $jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $suivant->is(T_VARIABLE)
            ? deuxChampsSorteDeLaClasse(deuxChampsClasseNommee($jeton, $portee))
            : null;

        if ($sorteDuParametre !== null) {
            $variables[$suivant->text] = $sorteDuParametre;

            continue;
        }

        $receveur = null;
        $sorte = 'champ';

        if ($jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $suivant->text === '::'
            && ($jetons[$indice + 2] ?? null)?->is(T_STRING) === true && ($jetons[$indice + 3]->text ?? '') === '(') {
            $classe = deuxChampsClasseNommee($jeton, $portee);
            $methode = strtolower($jetons[$indice + 2]->text);

            if ($methode === 'make' && deuxChampsEstUnChampDuPanneau($classe)) {
                $receveur = deuxChampsFermante($jetons, $indice + 3);
                $champs++;
            } elseif ($methode === 'numeric' && $classe === Rule::class) {
                [$receveur, $sorte] = [deuxChampsFermante($jetons, $indice + 3), 'numeric'];
            }
        } elseif ($jeton->is(T_NEW) && $suivant->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            // `new Numeric(…)` comme `new Numeric`, que des parenthèses groupent.
            $classe = deuxChampsClasseNommee($suivant, $portee);
            $finDuNew = ($jetons[$indice + 2]->text ?? '') === '(' ? deuxChampsFermante($jetons, $indice + 2) : $indice + 1;

            if (deuxChampsEstUnChampDuPanneau($classe)) {
                $receveur = $finDuNew;
                $champs++;
            } elseif ($classe === Numeric::class) {
                [$receveur, $sorte] = [$finDuNew, 'numeric'];
            }
        } elseif ($jeton->is(T_VARIABLE) && isset($variables[$jeton->text])) {
            // `$champ->…`, `$regle->…` : la chaîne part de la variable.
            [$receveur, $sorte] = [$indice, $variables[$jeton->text]];
        }

        if ($receveur === null) {
            continue;
        }

        [$debut, $receveur] = deuxChampsHorsDesParentheses($jetons, $indice, $receveur);

        // `$champ = TextInput::make(…);`, `$regle = (new Numeric);` : la variable porte un champ ou une règle.
        if (! $jeton->is(T_VARIABLE) && ($jetons[$debut - 1]->text ?? '') === '=' && ($jetons[$debut - 2] ?? null)?->is(T_VARIABLE) === true) {
            $variables[$jetons[$debut - 2]->text] = $sorte;
        }

        foreach (deuxChampsChaineDAppels($jetons, $receveur, $portee) as ['methode' => $methode, 'arguments' => $arguments, 'ligne' => $ligne]) {
            if (deuxChampsAppelCompareDeuxChamps($sorte, $methode, $arguments)) {
                $lisibles = array_map(static fn (string $argument): string => $argument === "\0" ? '…' : "'".deuxChampsLisible($argument)."'", $arguments);
                $fautes[] = "ligne {$ligne} : ->{$methode}(".implode(', ', $lisibles).')';
            }
        }
    }

    return ['fautes' => $fautes, 'champs' => $champs];
}

/*
 * Chaque écriture connue d'une comparaison entre deux champs, et ce qui n'en
 * est pas une. La garde voit le défaut qu'elle refuse : sans quoi elle ne
 * prouverait rien. La lecture des jetons voit une chaîne de règle à toute
 * place, même dans les paramètres d'un tableau imbriqué, où Laravel ne
 * l'appliquerait pas : elle refuse plus qu'il n'applique, jamais moins.
 */
it('reconnaît chaque écriture d’une comparaison entre deux champs', function (string $code, bool $compareDeuxChamps): void {
    $source = "<?php\n\nnamespace App\\Exemple;\n\nuse App\\Models\\Goal;\nuse Carbon\\Carbon;\nuse Filament\\Forms\\Components\\TextInput;\nuse Filament\\Forms\\Components\\Select as Choix;\nuse Filament\\Schemas\\Components\\Utilities\\Get;\nuse Illuminate\\Validation\\Rule;\nuse Illuminate\\Validation\\Rules\\Numeric;\n\n".$code;

    expect(deuxChampsLireLeCode($source)['fautes'] !== [])->toBe($compareDeuxChamps);
})->with([
    'une chaîne seule' => ["\$regles = ['target_value' => 'gte:start_value'];", true],
    'un élément de tableau' => ["\$regles = ['target_value' => ['required', 'numeric', 'gte:start_value']];", true],
    'une règle en tableau [nom, paramètre]' => ["\$regles = ['target_value' => ['required', 'numeric', ['gte', 'start_value']]];", true],
    'une règle en tableau par array(), en capitales, vers une variable' => ["\$regles = ['target_value' => array('numeric', array('LT', \$champ))];", true],
    'une chaîne de règle à toute place d’un tableau, même où Laravel ne l’applique pas' => ["\$regles = ['target_value' => ['bail', ['numeric', ['lt:start_value']]]];", true],
    'une liste de règles' => ["\$regles = ['target_value' => 'required|numeric|gt:start_value'];", true],
    'des capitales' => ["\$regles = ['target_value' => 'GTE:start_value'];", true],
    'un blanc avant les deux-points' => ["\$regles = ['target_value' => 'gte :start_value'];", true],
    'un nom en snake_case que Laravel ramène à la règle' => ["\$regles = ['target_value' => 'g_te:start_value'];", true],
    'une concaténation' => ["\$regles = ['target_value' => 'gte:'.\$champ];", true],
    'une concaténation de deux chaînes' => ["\$regles = ['target_value' => 'gte'.':'.'start_value'];", true],
    'une interpolation entre accolades' => ["\$regles = ['target_value' => \"gte:{\$champ}\"];", true],
    'une interpolation simple' => ["\$regles = ['target_value' => \"numeric|lte:\$champ\"];", true],
    'un heredoc' => ["\$regle = <<<TXT\nlt:{\$champ}\nTXT;", true],
    'Rule::numeric() vers un champ' => ["\$regles = ['target_value' => [Rule::numeric()->greaterThan('start_value')]];", true],
    'Rule::numeric() vers un champ, plus loin dans la chaîne' => ["\$regles = ['target_value' => [Rule::numeric()->min(0)->lessThanOrEqualTo('start_value')]];", true],
    'Numeric construit vers un champ' => ["\$regle = new Numeric()->greaterThanOrEqualTo('start_value');", true],
    'une borne tirée d’un autre champ' => ["\$regles = ['target_value' => ['numeric', 'min:'.\$this->input('start_value')]];", true],
    'une borne interpolée' => ["\$regles = ['a' => \"numeric|between:0,{\$max}\"];", true],
    'une borne en tableau tirée d’un autre champ' => ["\$regles = ['a' => ['numeric', ['max', \$this->input('b')]]];", true],
    'une borne de Rule::numeric() tirée d’un autre champ' => ["\$regles = ['a' => [Rule::numeric()->min((float) \$this->input('b'))]];", true],
    'Numeric construit entre parenthèses' => ["\$regle = (new Numeric)->greaterThan('start_value');", true],
    'Numeric construit entre parenthèses, avec ses arguments' => ["\$regle = (new Numeric(true))->lessThan('start_value');", true],
    'Rule::numeric() dans une variable' => ["\$regle = Rule::numeric();\n\$regle->greaterThan('start_value');", true],
    'Numeric construit dans une variable' => ["\$regle = new Numeric();\n\$regle->lessThan('start_value');", true],
    'Numeric construit entre parenthèses dans une variable' => ["\$regle = (new Numeric);\n\$regle->min(0)->greaterThanOrEqualTo('start_value');", true],
    'Numeric en paramètre typé' => ["\$regler = fn (Numeric \$regle): Numeric => \$regle->lessThanOrEqualTo('start_value');", true],
    'Numeric nommé en entier en paramètre typé' => ['$regler = fn (\\Illuminate\\Validation\\Rules\\Numeric $regle): mixed => $regle->max($borne);', true],
    'un champ du panneau, gte()' => ["\$champs = [TextInput::make('target_value')->label('Cible')->numeric()->gte('start_value')];", true],
    'un champ du panneau, lt() sur un alias' => ["\$champs = [Choix::make('fin')->label('Fin')->lt('debut')];", true],
    'un champ du panneau nommé en entier' => ["\$champs = [\\Filament\\Forms\\Components\\TextInput::make('a')->label('A')->lte('b')];", true],
    'un champ du panneau, fieldComparisonRule()' => ["\$champs = [TextInput::make('a')->label('A')->fieldComparisonRule('gt', 'b')];", true],
    'un champ du panneau, multiFieldComparisonRule()' => ["\$champs = [TextInput::make('a')->label('A')->multiFieldComparisonRule('gte', ['b', 'c'])];", true],
    'un champ du panneau dans une fermeture' => ["\$champs = fn (): array => [TextInput::make('a')->label('A')->required(fn (): bool => true)->gt('b')];", true],
    'un champ du panneau dans une variable' => ["\$champ = TextInput::make('a')->label('A');\n\$champ->numeric()->gte('b');", true],
    'un champ du panneau en paramètre typé' => ["\$regler = fn (TextInput \$champ): TextInput => \$champ->lte('b');", true],
    'un champ du panneau entre parenthèses' => ["\$champs = [(TextInput::make('a'))->gte('b')];", true],
    'un champ du panneau entre parenthèses dans une variable' => ["\$champ = (TextInput::make('a'));\n\$champ->gte('b');", true],
    'un champ du panneau, minValue() tirée d’un autre champ' => ["\$champs = [TextInput::make('target_value')->numeric()->minValue(fn (Get \$get): mixed => \$get('start_value'))];", true],
    'un champ du panneau, maxLength() d’une variable' => ["\$champs = [TextInput::make('a')->maxLength(\$borne)];", true],
    'une règle dans ->rule() du panneau' => ["\$champs = [TextInput::make('a')->label('A')->rule('gte:b')];", true],
    'un paramètre entier' => ["\$regles = ['target_value' => 'gte:0'];", false],
    'un paramètre décimal' => ["\$regles = ['target_value' => ['numeric', 'gte:1.5', 'lt:100']];", false],
    'un paramètre tenu par une constante' => ["\$regles = ['target_value' => ['numeric', 'lte:'.Goal::VALEUR_MAX]];", false],
    'un paramètre tenu par une constante de la classe du fichier' => ["namespace App\\Models;\n\nfinal class Goal\n{\n    public function regles(): array\n    {\n        return ['target_value' => ['numeric', 'gte:'.self::VALEUR_MAX]];\n    }\n}", false],
    'un paramètre tenu par une constante qui n’est pas un nombre' => ["\$regles = ['target_value' => 'gte:'.\\App\\Exceptions\\UneSeanceEstDejaEnCours::RAISON];", true],
    'une règle en tableau vers un nombre' => ["\$regles = ['a' => ['numeric', ['gte', 0], ['lt', '1.5'], ['lte', Goal::VALEUR_MAX], ['gt', -1]]];", false],
    'des bornes fixes' => ["\$regles = ['a' => ['numeric', 'min:0', 'max:'.Goal::VALEUR_MAX, 'between:1,10', 'size:3', 'multiple_of:0.5', ['max', 10]]];", false],
    'des bornes fixes au panneau' => ["\$champs = [TextInput::make('a')->minValue(0)->maxValue(Goal::VALEUR_MAX)->maxLength(255), TextInput::make('b')->minValue(-10)->maxValue('2.5')];", false],
    'des bornes fixes de Rule::numeric()' => ["\$regles = ['a' => [Rule::numeric()->min(0)->max(Goal::VALEUR_MAX)->between(1, 10)]];", false],
    'des bornes fixes de Rule::numeric() dans une variable' => ["\$regle = Rule::numeric();\n\$regle->min(0)->max(Goal::VALEUR_MAX)->greaterThan(0);", false],
    'un tableau de mots qui n’est pas une règle' => ["\$colonnes = ['min' => 'max', 'ordre' => ['gte' => 'x']];", false],
    'Rule::numeric() vers un nombre' => ["\$regles = ['target_value' => [Rule::numeric()->greaterThan('0')]];", false],
    'un objet de règle' => ["\$champs = [TextInput::make('a')->label('A')->rule(fn (): object => new \\App\\Rules\\CibleAuMoinsEgaleAuDepart(1))];", false],
    'gt() de Carbon' => ["\$fin = Carbon::now();\nif (Carbon::parse('2026-01-01')->gt(\$fin) || \$fin->lte(now())) {\n}", false],
    'une méthode gte() d’un objet qui n’est pas un champ' => ["\$borne = new \\stdClass();\n\$borne->gte('b');", false],
    'une phrase' => ["\$texte = 'Le champ doit être supérieur ou égal : à la valeur de départ.';", false],
    'une règle sans paramètre' => ["\$regles = ['a' => 'numeric|min:0|max:10'];", false],
]);

/*
 * La lecture des règles d'une requête suit Laravel au plus près : un tableau
 * imbriqué est une règle, son premier élément le nom, les suivants ses
 * paramètres (`ValidationRuleParser::parseArrayRule()`).
 */
it('lit la règle d’une requête comme Laravel l’applique', function (array $regles, bool $compareDeuxChamps): void {
    expect(array_filter(deuxChampsReglesEnTexte($regles), deuxChampsRegleCompareDeuxChamps(...)) !== [])->toBe($compareDeuxChamps);
})->with([
    'une chaîne' => [['required', 'gte:start_value'], true],
    'une liste' => [['required|numeric|lt:start_value'], true],
    'derrière Rule::when()' => [[Rule::when(true, ['numeric', 'gte:start_value'])], true],
    'du côté par défaut de Rule::when()' => [[Rule::when(false, ['numeric'], ['lte:start_value'])], true],
    'une règle en tableau [nom, paramètre]' => [['numeric', ['gte', 'start_value']], true],
    'une règle en tableau derrière Rule::when()' => [[Rule::when(true, ['numeric', ['LTE', 'start_value']])], true],
    'un tableau imbriqué, que Laravel lit [nom, paramètres…] et non comme une liste' => [[['numeric', ['gt:start_value']]], false],
    'une règle en tableau vers un nombre' => [[['gte', 0], ['lt', '1.5']], false],
    'un objet qui s’écrit en texte' => [[Rule::numeric()->greaterThan('start_value')], true],
    'un paramètre numérique' => [['numeric', 'gte:0', 'lt:1.5'], false],
    'une borne tirée d’un autre champ, vide sans saisie' => [['numeric', 'min:'], true],
    'une borne en tableau tirée d’un autre champ' => [['numeric', ['max', null]], true],
    'une borne entre deux valeurs dont l’une manque' => [['between:0,'], true],
    'des bornes fixes' => [['numeric', 'min:0', 'max:1000000', 'between:1,10', 'size:3', 'multiple_of:0.5', ['max', 10]], false],
    'Rule::numeric() borné par des nombres' => [[Rule::numeric()->min(0)->max(10)], false],
    'un objet de règle' => [[new App\Rules\CibleAuMoinsEgaleAuDepart(10)], false],
]);

it('ne laisse aucune requête comparer deux champs autrement que par un objet de règle', function (): void {
    $requetes = ReglesDesRequetes::toutes();
    $fautes = [];

    expect(count($requetes))->toBeGreaterThan(30, 'trop peu de requêtes lues : le test ne prouverait rien');

    foreach (array_keys($requetes) as $classe) {
        foreach (ReglesDesRequetes::brutesDe($classe) as $champ => $regles) {
            foreach (deuxChampsReglesEnTexte(is_array($regles) ? $regles : [$regles]) as $regle) {
                if (deuxChampsRegleCompareDeuxChamps($regle)) {
                    $fautes[] = "{$classe} ({$champ}) : {$regle}";
                }
            }
        }
    }

    expect($fautes)->toBe([], "comparaison entre deux champs à écrire par un objet de règle (.ai/rules/requests.md) :\n  ".implode("\n  ", $fautes));
});

it('ne laisse aucun code de app/ comparer deux champs autrement que par un objet de règle', function (): void {
    $fautes = [];
    $fichiers = 0;
    $champs = 0;

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
        $fichiers++;
        $lu = deuxChampsLireLeCode((string) file_get_contents((string) $fichier->getRealPath()));
        $champs += $lu['champs'];

        foreach ($lu['fautes'] as $faute) {
            $fautes[] = 'app/'.$fichier->getRelativePathname().", {$faute}";
        }
    }

    expect($fichiers)->toBeGreaterThan(300, 'trop peu de fichiers lus : le test ne prouverait rien')
        ->and($champs)->toBeGreaterThan(30, 'trop peu de champs du panneau reconnus : la résolution des classes ne marche plus');

    expect($fautes)->toBe([], "comparaison entre deux champs à écrire par un objet de règle (.ai/rules/requests.md) :\n  ".implode("\n  ", $fautes));
});
