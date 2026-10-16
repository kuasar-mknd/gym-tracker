<?php

declare(strict_types=1);

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\Support\FilamentAdminPanel;

/*
 * Chaque champ libre d'un formulaire du panneau tient dans la colonne qu'il
 * écrit (#2071). Filament ne borne rien de lui-même : un nom de plus de 255
 * caractères, un repos plus grand qu'un entier ou une catégorie qu'aucune
 * `ExerciseCategory` ne lit allaient jusqu'à la base ou jusqu'au cast du
 * modèle, et l'enregistrement répondait 500.
 *
 * La garde monte chaque page de modification du panneau sur une ligne de sa
 * fabrique, lit les champs `TextInput` et `Textarea` de son formulaire,
 * cachés compris, qui s'écrivent (ni désactivés, ni hors de la
 * déshydratation : un mot de passe vide n'en est pas), et confronte chacun à
 * la colonne de même nom de la table du modèle : une chaîne a une longueur
 * maximale sous celle de sa colonne (un `TEXT` compte quatre octets par
 * caractère), un entier est saisi comme un entier, entre deux bornes que sa
 * colonne tient, et une colonne que le modèle lit comme une énumération
 * n'a pas de saisie libre. Un nombre à virgule flottante (`double`) tient
 * tout nombre que PHP lit. Les dates ont leur plage par
 * `DateQueSaColonneTient` (`.ai/rules/filament.md`) ; une liste (`Select`)
 * se valide par ses options. Une colonne sans champ de même nom (un champ
 * calculé, une relation) n'est pas confrontée.
 */

/**
 * Les pages de modification du panneau.
 *
 * @return list<class-string<EditRecord>>
 */
function champsDuPanneauPages(): array
{
    $pages = [];

    foreach (Finder::create()->files()->in(app_path('Filament'))->name('Edit*.php') as $fichier) {
        $classe = 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (is_subclass_of($classe, EditRecord::class)) {
            $pages[] = $classe;
        }
    }

    sort($pages);

    return $pages;
}

/**
 * Le modèle de la ressource d'une page.
 *
 * @param  class-string<EditRecord>  $page
 * @return class-string<Model>
 */
function champsDuPanneauModele(string $page): string
{
    $ressource = $page::getResource();
    $modele = is_subclass_of($ressource, Resource::class) ? $ressource::getModel() : null;

    return is_string($modele) && is_subclass_of($modele, Model::class) ? $modele : throw new LogicException("Modèle illisible : {$page}.");
}

/**
 * Les champs du formulaire d'une page, montée sur une ligne de sa fabrique,
 * et cette ligne.
 *
 * @param  class-string<EditRecord>  $page
 * @return array{0: Model, 1: array<Field>}
 */
function champsDuPanneauChamps(string $page): array
{
    $ligne = Factory::factoryForModel(champsDuPanneauModele($page))->createOne();
    $composant = Livewire::test($page, ['record' => $ligne->getKey()])->instance();
    $formulaire = $composant instanceof EditRecord ? $composant->getSchema('form') : null;

    return [$ligne, $formulaire?->getFlatFields(withHidden: true) ?? []];
}

/**
 * Les bornes d'une colonne entière.
 *
 * @return array{0: float, 1: float}|null
 */
function champsDuPanneauBornesEntieres(string $type): ?array
{
    if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $type, $entier) !== 1) {
        return null;
    }

    $bits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'bigint' => 64][$entier[1]];

    return str_contains($type, 'unsigned')
        ? [0.0, 2 ** $bits - 1]
        : [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1];
}

/**
 * Ce qui, dans un champ, laisse passer ce que sa colonne refuse.
 *
 * @return list<string>
 */
function champsDuPanneauFautes(TextInput|Textarea $champ, string $type, ?string $cast): array
{
    if (is_string($cast) && enum_exists($cast)) {
        return ["saisie libre pour une colonne que le modèle lit comme {$cast}"];
    }

    $longueur = $champ->getMaxLength();
    $capacite = match (true) {
        preg_match('/^(?:var)?char\((\d+)\)/', $type, $chaine) === 1 => (int) $chaine[1],
        preg_match('/^(tiny|medium|long)?text\b/', $type, $texte) === 1 => intdiv(['tiny' => 255, '' => 65_535, 'medium' => 16_777_215, 'long' => 4_294_967_295][$texte[1] ?? ''], 4),
        default => null,
    };

    if ($capacite !== null) {
        return $longueur === null || $longueur > $capacite
            ? ['accepte '.($longueur ?? 'toute longueur').", la colonne ({$type}) s'arrête à {$capacite} caractères"]
            : [];
    }

    $bornes = champsDuPanneauBornesEntieres($type);

    if ($bornes === null) {
        return [];
    }

    if (! $champ instanceof TextInput || ! $champ->isInteger()) {
        return ["n'est pas saisi comme un entier, pour une colonne {$type}"];
    }

    $min = $champ->getMinValue();
    $max = $champ->getMaxValue();

    return array_values(array_filter([
        ! is_numeric($min) || (float) $min < $bornes[0] ? "n'a pas de borne basse que la colonne ({$type}) tient" : null,
        ! is_numeric($max) || (float) $max > $bornes[1] ? "n'a pas de borne haute que la colonne ({$type}) tient" : null,
    ]));
}

it('borne chaque champ libre du panneau sous la capacité de sa colonne', function (): void {
    $pages = champsDuPanneauPages();
    $permissions = [];

    foreach ($pages as $page) {
        array_push($permissions, ...FilamentAdminPanel::crudPermissions(class_basename(champsDuPanneauModele($page))));
    }

    $this->actingAs(FilamentAdminPanel::admin($permissions), 'admin');
    $fautes = [];
    $confrontes = 0;

    foreach ($pages as $page) {
        [$ligne, $champs] = champsDuPanneauChamps($page);
        $colonnes = collect(Schema::getColumns($ligne->getTable()))->keyBy('name');

        foreach ($champs as $champ) {
            $colonne = $colonnes->get($champ->getName());

            if ((! $champ instanceof TextInput && ! $champ instanceof Textarea)
                || $champ->isDisabled()
                || ! $champ->isDehydrated()
                || ! is_array($colonne)
                || ! is_string($colonne['type'] ?? null)) {
                continue;
            }

            $confrontes++;
            $cast = $ligne->getCasts()[$champ->getName()] ?? null;

            foreach (champsDuPanneauFautes($champ, strtolower($colonne['type']), is_string($cast) ? $cast : null) as $faute) {
                $fautes[] = class_basename($page)." : {$champ->getName()} {$faute}";
            }
        }
    }

    expect($fautes)->toBe([], "champs du panneau plus larges que leur colonne :\n  ".implode("\n  ", $fautes))
        ->and(count($pages))->toBeGreaterThanOrEqual(6)
        ->and($confrontes)->toBeGreaterThanOrEqual(20);
});

/*
 * La garde voit le défaut d'origine : sans quoi elle ne prouverait rien.
 */
it('refuse les champs d’origine du panneau, et accepte les champs bornés', function (): void {
    expect(champsDuPanneauFautes(TextInput::make('name'), 'varchar(255)', null))->toHaveCount(1)
        ->and(champsDuPanneauFautes(TextInput::make('name')->maxLength(255), 'varchar(255)', null))->toBe([])
        ->and(champsDuPanneauFautes(Textarea::make('notes')->maxLength(20_000), 'text', null))->toHaveCount(1)
        ->and(champsDuPanneauFautes(TextInput::make('default_rest_time')->numeric(), 'int', null))->toHaveCount(1)
        ->and(champsDuPanneauFautes(TextInput::make('default_rest_time')->integer()->minValue(0), 'int', null))->toHaveCount(1)
        ->and(champsDuPanneauFautes(TextInput::make('default_rest_time')->integer()->minValue(0)->maxValue(3_600), 'int', null))->toBe([])
        ->and(champsDuPanneauFautes(TextInput::make('category'), 'varchar(255)', App\Enums\ExerciseCategory::class))->toHaveCount(1)
        ->and(champsDuPanneauFautes(TextInput::make('target_value')->numeric(), 'double', null))->toBe([]);
});
