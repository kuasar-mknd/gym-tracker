<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Une colonne ou un champ du panneau déclaré sans `->label()` reçoit son nom
 * de colonne mis en forme : « Default rest time », « Current streak »,
 * « Created at ». Le panneau était ainsi à moitié anglais (#1979).
 *
 * La garde lit chaque `make()` d'une colonne, d'un champ, d'un filtre ou d'une
 * entrée dans app/Filament et exige un `->label()` dans la chaîne qui le
 * suit, au même niveau (un `label` écrit dans une fermeture passée en argument
 * ne compte pas). Les actions et les tuiles de statistiques ne sont pas
 * visées : Filament traduit les premières, et les secondes prennent leur
 * libellé en premier argument.
 */

/**
 * Les composants qui portent un libellé, d'après la fin de leur nom de classe.
 */
function panneauComposantNomme(string $classe): bool
{
    return preg_match('/(Column|Input|Select|Textarea|Picker|Toggle|Checkbox|CheckboxList|Filter|Entry|Repeater|Radio|KeyValue|FileUpload|Editor)$/', $classe) === 1;
}

/**
 * Chaque `make()` de composant nommé sans `->label()` dans sa chaîne.
 *
 * @return list<string> « ligne : Classe::make('champ') »
 */
function panneauChampsSansLibelle(string $source): array
{
    $jetons = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $jeton): bool => ! is_array($jeton) || ! in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $texte = static fn (mixed $jeton): string => is_string($jeton) ? $jeton : (is_array($jeton) && is_string($jeton[1] ?? null) ? $jeton[1] : '');
    $sansLibelle = [];

    foreach ($jetons as $i => $jeton) {
        if (! is_array($jeton) || ! in_array($jeton[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $classe = (string) preg_replace('/^.*\\\\/', '', $jeton[1]);

        if (! panneauComposantNomme($classe) || $texte($jetons[$i + 1] ?? '') !== '::' || $texte($jetons[$i + 2] ?? '') !== 'make' || $texte($jetons[$i + 3] ?? '') !== '(') {
            continue;
        }

        $champ = trim($texte($jetons[$i + 4] ?? ''), '\'"');
        $methodes = [];
        $profondeur = 0;
        $j = $i + 3;

        while (isset($jetons[$j])) {
            $courant = $texte($jetons[$j]);

            if (in_array($courant, ['(', '[', '{'], true) || (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $profondeur++;
            } elseif (in_array($courant, [')', ']', '}'], true)) {
                $profondeur--;

                if ($profondeur === 0 && $texte($jetons[$j + 1] ?? '') !== '->') {
                    break;
                }
            } elseif ($profondeur === 0 && $courant === '->') {
                $methodes[] = $texte($jetons[$j + 1] ?? '');
            }

            $j++;
        }

        if (! in_array('label', $methodes, true)) {
            $sansLibelle[] = "{$jeton[2]} : {$classe}::make('{$champ}')";
        }
    }

    return $sansLibelle;
}

it('exige un libellé sur chaque colonne, champ, filtre et entrée du panneau', function (): void {
    $fautes = [];
    $lus = 0;

    foreach (Finder::create()->files()->in(app_path('Filament'))->name('*.php') as $fichier) {
        $lus++;

        foreach (panneauChampsSansLibelle($fichier->getContents()) as $faute) {
            $fautes[] = 'app/Filament/'.$fichier->getRelativePathname().':'.$faute;
        }
    }

    expect($lus)->toBeGreaterThan(30)
        ->and($fautes)->toBe([], 'posez un ->label() en français : sans lui, Filament affiche le nom de la colonne, en anglais');
});

it('repère un champ sans libellé, même quand la chaîne en porte un dans une fermeture', function (): void {
    $source = <<<'PHP'
        <?php
        return [
            TextColumn::make('nouvelle_colonne')->sortable(),
            TextColumn::make('etat')->state(fn ($r) => $r->label('x'))->badge(),
            \Filament\Forms\Components\TextInput::make('target_value')
                ->required()
                ->numeric(),
            TextInput::make('nom')->label('Nom')->required(),
            Select::make('type')->options(['a' => 'A'])->label('Type'),
            EditAction::make(),
        ];
        PHP;

    expect(panneauChampsSansLibelle($source))->toBe([
        "3 : TextColumn::make('nouvelle_colonne')",
        "4 : TextColumn::make('etat')",
        "5 : TextInput::make('target_value')",
    ]);
});
