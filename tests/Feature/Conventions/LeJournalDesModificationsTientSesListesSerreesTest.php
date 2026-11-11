<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * Une section de CHANGELOG.md est une liste serrée : ses entrées se suivent,
 * et seule une ligne vide la sépare du titre suivant. Une ligne vide entre
 * deux entrées rend toute la liste « lâche » en CommonMark, chaque entrée
 * dans son propre paragraphe, et l'entrée d'après paraît détachée de sa
 * section. C'est ce qu'avait fait une entrée de #2151, posée après la ligne
 * vide qui fermait « ### Ajouté ».
 *
 * La garde lit la section [Unreleased], où chaque lot écrit, et que la
 * release suivante publie telle quelle. Les versions publiées restent ce
 * qu'elles ont été.
 */

/**
 * La section [Unreleased] du journal, et le rang de sa première ligne.
 *
 * @return array{lignes: list<string>, premiere: int}
 */
function journalSectionEnCours(string $journal): array
{
    $lignes = explode("\n", $journal);
    $debut = array_search('## [Unreleased]', $lignes, true);

    if ($debut === false) {
        return ['lignes' => [], 'premiere' => 0];
    }

    $section = [];

    foreach (array_slice($lignes, $debut + 1) as $ligne) {
        if (str_starts_with($ligne, '## ')) {
            break;
        }

        $section[] = $ligne;
    }

    return ['lignes' => $section, 'premiere' => $debut + 2];
}

/**
 * Les lignes, comptées depuis `$premiere`, des entrées qu'une ligne vide
 * sépare de l'entrée d'avant.
 *
 * @param  list<string>  $lignes
 * @return list<int>
 */
function journalEntreesDetachees(array $lignes, int $premiere = 1): array
{
    $detachees = [];
    $derniere = null;
    $videAvant = false;

    foreach ($lignes as $rang => $ligne) {
        if (trim($ligne) === '') {
            $videAvant = true;

            continue;
        }

        if ($videAvant && $derniere !== null && str_starts_with($ligne, '- ') && ! str_starts_with($derniere, '#')) {
            $detachees[] = $premiere + $rang;
        }

        $derniere = $ligne;
        $videAvant = false;
    }

    return $detachees;
}

it('tient serrées les listes de la section [Unreleased] du journal', function (): void {
    ['lignes' => $lignes, 'premiere' => $premiere] = journalSectionEnCours(File::get(base_path('CHANGELOG.md')));

    expect($lignes)->not->toBeEmpty('CHANGELOG.md n’a plus de section [Unreleased].')
        ->and(journalEntreesDetachees($lignes, $premiere))->toBe([], 'Ces lignes de CHANGELOG.md ouvrent une entrée qu’une ligne vide sépare de la précédente : la liste devient lâche. Retire la ligne vide.');
});

it('refuse une ligne vide entre deux entrées, et accepte celle qui précède un titre', function (string $section, array $attendu): void {
    expect(journalEntreesDetachees(explode("\n", $section)))->toBe($attendu);
})->with([
    'liste serrée' => ["### Ajouté\n- un\n- deux\n\n### Modifié\n- trois\n", []],
    'ligne vide entre deux entrées' => ["### Ajouté\n- un\n\n- deux\n\n### Modifié\n", [4]],
    'deux lignes vides' => ["### Ajouté\n- un\n\n\n- deux\n", [5]],
    'entrée suivie d’un paragraphe, puis d’une entrée' => ["### Ajouté\n- un\n  suite\n\n- deux\n", [5]],
    'ligne vide après le titre' => ["### Ajouté\n\n- un\n", []],
]);
