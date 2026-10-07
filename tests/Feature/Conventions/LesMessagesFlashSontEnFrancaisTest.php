<?php

declare(strict_types=1);

/*
 * Un message flash s'affiche tel quel, en toast, au milieu d'une interface
 * française (`HandleInertiaRequests::share()`, layout authentifié).
 *
 * Trois contrôleurs l'écrivaient en anglais (« Timer created successfully. »,
 * « Fast started successfully. », « Measurement added. »), quand tous les
 * autres disent « Habitude créée. » ou « Objectif mis à jour. » (#1973). Le
 * toast porte `role="alert"` : un lecteur d'écran réglé en français lisait
 * donc la phrase anglaise avec une prononciation française.
 *
 * La garde lit dans app/ chaque message posé en dur sous une clef que le
 * layout affiche (`success`, `error`), par `->with()` ou par un `flash()`, et
 * refuse ceux qui portent un mot anglais courant d'un message de
 * confirmation. Un message passé par `__()` est jugé sur sa traduction
 * française, ou sur sa clef quand `lang/fr.json` ne la traduit pas. Un message
 * calculé (variable, concaténation) lui échappe.
 */

/**
 * Le texte affiché pour un message littéral ou passé par `__()`.
 *
 * @param  array<string, string>  $traductions
 */
function messagesFlashTexteAffiche(string $litteral, bool $traduit, array $traductions): string
{
    $texte = stripcslashes($litteral);

    return $traduit ? ($traductions[$texte] ?? $texte) : $texte;
}

/**
 * Vrai si le message porte un mot qui trahit une confirmation ou un échec
 * écrits en anglais.
 *
 * Des mots, pas des fragments : « succès », « ajouté » ou « erreur » ne
 * tombent pas dedans.
 */
function messagesFlashEstAnglais(string $message): bool
{
    return preg_match(
        '/\b(successfully|success|created|updated|deleted|added|removed|saved|started|stopped|completed|failed|unable|error|invalid|please|the|your|has|been|was|could|not)\b/iu',
        $message,
    ) === 1;
}

/**
 * Chaque message flash écrit en dur dans app/, avec son fichier et sa ligne.
 *
 * @return list<array{fichier: string, ligne: int, message: string}>
 */
function messagesFlashDeLApplication(): array
{
    /** @var array<string, string> $traductions */
    $traductions = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    $motif = '/(?:->with|flash)\(\s*([\'"])(?:success|error)\1\s*,\s*(__\(\s*)?([\'"])((?:\\\\.|(?!\3).)*)\3/s';
    $messages = [];

    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterateur as $fichier) {
        if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        preg_match_all($motif, $source, $correspondances, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($correspondances as $correspondance) {
            $messages[] = [
                'fichier' => str_replace(base_path().'/', '', $fichier->getPathname()),
                'ligne' => substr_count(substr($source, 0, $correspondance[0][1]), "\n") + 1,
                'message' => messagesFlashTexteAffiche(
                    $correspondance[4][0],
                    $correspondance[2][0] !== '',
                    $traductions,
                ),
            ];
        }
    }

    return $messages;
}

it('écrit en français chaque message flash de app/', function (): void {
    $messages = messagesFlashDeLApplication();

    // Sans message trouvé, le motif aurait cessé de lire le code : la garde
    // passerait sans rien vérifier.
    expect(count($messages))->toBeGreaterThan(10);

    $anglais = array_values(array_map(
        static fn (array $message): string => "{$message['fichier']}:{$message['ligne']} « {$message['message']} »",
        array_filter($messages, static fn (array $message): bool => messagesFlashEstAnglais($message['message'])),
    ));

    expect($anglais)->toBe([], 'ces messages flash sont en anglais ; le toast les lit tels quels dans une interface française');
});

it('reconnaît les messages anglais qu’elle existe pour refuser', function (string $message): void {
    expect(messagesFlashEstAnglais($message))->toBeTrue();
})->with([
    'Timer created successfully.',
    'Timer updated successfully.',
    'Timer deleted successfully.',
    'Fast started successfully.',
    'Fast updated successfully.',
    'Fast deleted successfully.',
    'Measurement added.',
    'Measurement deleted.',
]);

it('laisse passer les messages français', function (string $message): void {
    expect(messagesFlashEstAnglais($message))->toBeFalse();
})->with([
    'Objectif créé avec succès.',
    'Habitude supprimée.',
    'Complément ajouté.',
    'Consommation enregistrée.',
    'Minuteur créé.',
    'Jeûne commencé.',
    'Mesure ajoutée.',
]);
