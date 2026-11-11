<?php

declare(strict_types=1);

/*
 * Une page qui reçoit une réponse partielle passe ses props immédiates en
 * fermetures, et la règle qui le demande la nomme (#2148, étape 3a).
 *
 * Inertia ne saute, dans une réponse partielle, que les props encore à
 * calculer : une page qui étale des valeurs déjà calculées les relit toutes
 * pour la requête de sa prop différée, puis la réponse les jette. La règle
 * (`.ai/rules/controllers.md`, « Sur une page à réponse partielle, une prop
 * qui coûte se passe en fermeture ») ne vaut que pour ces pages, et la première version l'écrivait
 * pour toutes, quand GoalController ou PlateController passent encore des
 * valeurs, sans réponse partielle où elles coûteraient. Elle nomme donc les
 * contrôleurs qu'elle tient.
 *
 * La garde lit chaque contrôleur de app/Http/Controllers : celui qui pose une
 * prop différée (`Inertia::defer(`) doit être nommé, entre accents graves, dans
 * la section de la règle, et chaque contrôleur qu'elle nomme doit en poser
 * une. Une prop différée nouvelle fait donc tomber la garde, qui renvoie à la
 * règle : les props immédiates de la page passent en fermetures, puis la règle
 * la nomme.
 */

/** Le titre de la section de la règle, dans `.ai/rules/controllers.md`. */
const PROPS_DIFFEREES_TITRE_DE_LA_REGLE = '## Sur une page à réponse partielle, une prop qui coûte se passe en fermeture';

/**
 * Le texte de la section de la règle, jusqu'au titre suivant.
 */
function propsDiffereesSectionDeLaRegle(): string
{
    $regles = (string) file_get_contents(base_path('.ai/rules/controllers.md'));
    $debut = strpos($regles, PROPS_DIFFEREES_TITRE_DE_LA_REGLE);

    expect($debut)->not->toBeFalse('La section « '.PROPS_DIFFEREES_TITRE_DE_LA_REGLE.' » manque à .ai/rules/controllers.md.');

    $section = substr($regles, (int) $debut + strlen(PROPS_DIFFEREES_TITRE_DE_LA_REGLE));
    $suivante = strpos($section, "\n## ");

    return $suivante === false ? $section : substr($section, 0, $suivante);
}

/**
 * Les contrôleurs de app/Http/Controllers, par nom court, avec vrai pour ceux
 * qui posent une prop différée.
 *
 * @return array<string, bool>
 */
function propsDiffereesControleurs(): array
{
    $controleurs = [];
    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Http/Controllers'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterateur as $fichier) {
        if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $nom = $fichier->getBasename('.php');

        // Deux contrôleurs peuvent partager un nom court (WorkoutController
        // de la page et celui de l'API) : l'ordre du parcours du disque ne
        // doit pas décider lequel compte. Le nom pose une prop différée dès
        // que l'un de ses fichiers en pose une.
        $controleurs[$nom] = ($controleurs[$nom] ?? false) || str_contains($source, 'Inertia::defer(');
    }

    ksort($controleurs);

    return $controleurs;
}

/**
 * Les contrôleurs que la section nomme entre accents graves (`HabitController`,
 * `WorkoutController::show`).
 *
 * @return list<string>
 */
function propsDiffereesControleursNommes(string $section): array
{
    preg_match_all('/`(\w+Controller)\b[^`]*`/u', $section, $correspondances);

    $noms = array_values(array_unique($correspondances[1]));
    sort($noms);

    return $noms;
}

it('nomme dans la règle des fermetures chaque contrôleur qui pose une prop différée', function (): void {
    $nommes = propsDiffereesControleursNommes(propsDiffereesSectionDeLaRegle());
    $differes = array_keys(array_filter(propsDiffereesControleurs()));
    $oublies = array_values(array_diff($differes, $nommes));

    expect($differes)->not->toBeEmpty()
        ->and($oublies)->toBe([], sprintf(
            "Ces contrôleurs posent une prop différée sans que la règle les nomme : %s.\n\n"
            ."Une réponse partielle ne saute que les props encore à calculer : passe les props immédiates de la page en fermetures,\n"
            .'puis nomme le contrôleur dans « %s » (.ai/rules/controllers.md).',
            implode(', ', $oublies),
            substr(PROPS_DIFFEREES_TITRE_DE_LA_REGLE, 3),
        ));
});

it('ne nomme dans la règle des fermetures que des contrôleurs qui posent une prop différée', function (): void {
    $controleurs = propsDiffereesControleurs();
    $nommes = propsDiffereesControleursNommes(propsDiffereesSectionDeLaRegle());
    $enTrop = array_values(array_filter($nommes, static fn (string $nom): bool => ($controleurs[$nom] ?? false) === false));

    expect($nommes)->not->toBeEmpty()
        ->and($enTrop)->toBe([], sprintf(
            "La règle nomme des contrôleurs absents de app/Http/Controllers ou sans prop différée : %s.\n\n"
            .'Retire-les de « %s » (.ai/rules/controllers.md), ou nomme-les sans accents graves.',
            implode(', ', $enTrop),
            substr(PROPS_DIFFEREES_TITRE_DE_LA_REGLE, 3),
        ));
});
