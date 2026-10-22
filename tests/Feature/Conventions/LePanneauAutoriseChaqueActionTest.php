<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Support\Components\ComponentManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\Support\FilamentAdminPanel;

/*
 * Chaque action du panneau se décide sur une capacité, et aucune ne passe
 * faute de règle.
 *
 * Filament consulte, pour une ressource, douze méthodes de sa policy
 * (`panneauAutorisationsMethodesConsultees()`) ; le panneau est en autorisation stricte
 * (`strictAuthorization()`), si bien qu'une méthode absente lève au lieu
 * d'autoriser : chaque policy d'une ressource du panneau, celles des greffons
 * comprises, les porte toutes, et un administrateur sans aucune permission
 * n'en obtient rien. Shield les écrit dans les policies qu'il génère
 * (`filament-shield.policies.methods`).
 *
 * Une suppression, restauration ou suppression définitive groupée vérifie
 * chaque ligne (`authorizeIndividualRecords()`), sans contourner la lecture
 * des lignes (`fetchSelectedRecords(false)` supprimerait par la requête,
 * sans elles) ; toute autre action groupée, et toute action d'une page ou
 * d'une table qui n'a pas d'autorisation par défaut chez Filament, porte la
 * sienne (`authorize()`). La garde monte la page de liste de chaque ressource
 * du panneau, greffons compris, et lit aussi le texte de app/ : une
 * `DeleteBulkAction` ou une `Action` écrite hors d'une table de ressource se
 * voit là. Les tables des greffons reçoivent la vérification ligne à ligne de
 * la configuration globale de `DeleteBulkAction` (`AdminPanelProvider`), qui
 * survit d'une requête à l'autre sous Octane.
 */

/**
 * Les méthodes de policy que Filament consulte pour une ressource
 * (`Filament\Resources\Resource\Concerns\HasAuthorization`).
 *
 * @return list<string>
 */
function panneauAutorisationsMethodesConsultees(): array
{
    return [
        'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
        'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder',
    ];
}

/**
 * Celles qui reçoivent une ligne en second argument.
 *
 * @return list<string>
 */
function panneauAutorisationsMethodesDUneLigne(): array
{
    return ['view', 'update', 'delete', 'forceDelete', 'restore', 'replicate'];
}

/**
 * Les ressources du panneau, celles des greffons comprises.
 *
 * @return list<class-string<resource>>
 */
function panneauAutorisationsRessources(): array
{
    $ressources = array_values(array_filter(
        Filament::getPanel('admin')->getResources(),
        static fn (string $ressource): bool => is_subclass_of($ressource, Resource::class),
    ));
    sort($ressources);

    return $ressources;
}

/**
 * Les méthodes enchaînées derrière chaque `Classe::make(…)` d'un texte PHP,
 * au même niveau (une fermeture passée en argument ne compte pas).
 *
 * @return list<array{ligne: int, methodes: list<string>}>
 */
function panneauAutorisationsChaines(string $source, string $classe): array
{
    $jetons = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $jeton): bool => ! is_array($jeton) || ! in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $texte = static fn (mixed $jeton): string => is_string($jeton) ? $jeton : (is_array($jeton) && is_string($jeton[1] ?? null) ? $jeton[1] : '');
    $chaines = [];

    foreach ($jetons as $i => $jeton) {
        if (! is_array($jeton) || ! in_array($jeton[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        if (preg_replace('/^.*\\\\/', '', $jeton[1]) !== $classe || $texte($jetons[$i + 1] ?? '') !== '::' || $texte($jetons[$i + 2] ?? '') !== 'make' || $texte($jetons[$i + 3] ?? '') !== '(') {
            continue;
        }

        $methodes = [];
        $profondeur = 0;
        $j = $i + 3;

        while (isset($jetons[$j])) {
            $courant = $texte($jetons[$j]);

            if (in_array($courant, ['(', '[', '{'], true) || (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $profondeur++;
            } elseif (in_array($courant, [')', ']', '}'], true)) {
                $profondeur--;

                if ($profondeur === 0 && ! in_array($texte($jetons[$j + 1] ?? ''), ['->', '?->'], true)) {
                    break;
                }
            } elseif ($profondeur === 0 && in_array($courant, ['->', '?->'], true)) {
                $methodes[] = $texte($jetons[$j + 1] ?? '');
            }

            $j++;
        }

        $chaines[] = ['ligne' => (int) $jeton[2], 'methodes' => $methodes];
    }

    return $chaines;
}

/**
 * Ce qui manque, dans un texte PHP, aux suppressions groupées et aux actions
 * écrites à la main.
 *
 * @return list<string> « ligne : ce qui manque »
 */
function panneauAutorisationsFautesDuTexte(string $source): array
{
    $fautes = [];

    foreach (panneauAutorisationsChaines($source, 'DeleteBulkAction') as ['ligne' => $ligne, 'methodes' => $methodes]) {
        if (! in_array('authorizeIndividualRecords', $methodes, true)) {
            $fautes[] = "{$ligne} : DeleteBulkAction sans authorizeIndividualRecords()";
        }

        if (in_array('fetchSelectedRecords', $methodes, true)) {
            $fautes[] = "{$ligne} : DeleteBulkAction qui ne lit pas ses lignes";
        }
    }

    foreach (['Action', 'BulkAction'] as $classe) {
        foreach (panneauAutorisationsChaines($source, $classe) as ['ligne' => $ligne, 'methodes' => $methodes]) {
            if (array_intersect(['authorize', 'authorizeAny'], $methodes) === []) {
                $fautes[] = "{$ligne} : {$classe} sans authorize()";
            }
        }
    }

    return $fautes;
}

/**
 * Les actions que Filament autorise d'office sur une page de ressource, par
 * la policy (`Filament\Resources\Pages\Page::getDefaultActionAuthorizationResponse()`).
 *
 * @return list<class-string<Action>>
 */
function panneauAutorisationsActionsAutoriseesDOffice(): array
{
    return [
        CreateAction::class, DeleteAction::class, EditAction::class, ForceDeleteAction::class, ReplicateAction::class,
        RestoreAction::class, ViewAction::class, DeleteBulkAction::class, ForceDeleteBulkAction::class, RestoreBulkAction::class,
    ];
}

/**
 * Ce qui manque à une action montée sur une page du panneau.
 */
function panneauAutorisationsFauteDeLAction(Action $action): ?string
{
    $nom = $action::class.' « '.$action->getName().' »';

    if ($action instanceof DeleteBulkAction || $action instanceof ForceDeleteBulkAction || $action instanceof RestoreBulkAction) {
        return match (true) {
            ! $action->shouldAuthorizeIndividualRecords() => "{$nom} ne vérifie pas chaque ligne",
            ! $action->shouldFetchSelectedRecords() => "{$nom} ne lit pas ses lignes",
            default => null,
        };
    }

    if ($action instanceof BulkAction) {
        return $action->hasAuthorization() ? null : "{$nom} n'a pas d'autorisation";
    }

    $autoriseeDOffice = array_filter(
        panneauAutorisationsActionsAutoriseesDOffice(),
        static fn (string $classe): bool => $action instanceof $classe,
    ) !== [];

    return $action->hasAuthorization() || $autoriseeDOffice ? null : "{$nom} n'a pas d'autorisation";
}

/**
 * Les actions d'une liste, groupes dépliés.
 *
 * @param  array<Action|ActionGroup>  $actions
 * @return list<Action>
 */
function panneauAutorisationsDeplier(array $actions): array
{
    $depliees = [];

    foreach ($actions as $action) {
        if ($action instanceof ActionGroup) {
            $depliees = [...$depliees, ...panneauAutorisationsDeplier($action->getActions())];
        } else {
            $depliees[] = $action;
        }
    }

    return $depliees;
}

it('met le panneau en autorisation stricte', function (): void {
    expect(Filament::getPanel('admin')->isAuthorizationStrict())->toBeTrue();
});

it('donne à chaque ressource du panneau une policy qui porte chaque méthode que Filament consulte', function (): void {
    $fautes = [];
    $ressources = panneauAutorisationsRessources();

    foreach ($ressources as $ressource) {
        $policy = Gate::getPolicyFor($ressource::getModel());

        if (! is_object($policy)) {
            $fautes[] = "{$ressource} : aucune policy pour ".$ressource::getModel();

            continue;
        }

        foreach (array_diff(panneauAutorisationsMethodesConsultees(), get_class_methods($policy)) as $methode) {
            $fautes[] = $policy::class." : {$methode}() manque";
        }
    }

    expect(count($ressources))->toBeGreaterThanOrEqual(11)
        ->and($fautes)->toBe([], 'une méthode absente lève en autorisation stricte : écrivez-la, false quand le panneau ne l’offre pas')
        ->and(config('filament-shield.policies.methods'))->toContain(...panneauAutorisationsMethodesConsultees());
});

it('n’accorde rien d’aucune policy du panneau à un administrateur sans permission', function (): void {
    $administrateur = FilamentAdminPanel::admin();
    $accordees = [];

    foreach (panneauAutorisationsRessources() as $ressource) {
        $modele = $ressource::getModel();

        foreach (panneauAutorisationsMethodesConsultees() as $methode) {
            $argument = in_array($methode, panneauAutorisationsMethodesDUneLigne(), true) ? new $modele() : $modele;

            if (Gate::forUser($administrateur)->allows($methode, [$argument])) {
                $accordees[] = "{$modele}::{$methode}";
            }
        }
    }

    expect($accordees)->toBe([]);
});

it('vérifie chaque ligne des suppressions groupées et autorise chaque action des pages de liste du panneau', function (): void {
    $permissions = [];

    foreach (panneauAutorisationsRessources() as $ressource) {
        $permissions = [...$permissions, ...FilamentAdminPanel::crudPermissions(class_basename($ressource::getModel()))];
    }

    $administrateur = FilamentAdminPanel::admin($permissions);
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $this->actingAs($administrateur, 'admin');
    $fautes = [];
    $suppressionsGroupees = 0;

    foreach (panneauAutorisationsRessources() as $ressource) {
        $page = Livewire::test($ressource::getPages()['index']->getPage())->instance();

        if (! $page instanceof ListRecords) {
            $fautes[] = "{$ressource} : sa page de liste n'est pas une ListRecords";

            continue;
        }

        $actions = [
            ...panneauAutorisationsDeplier($page->getCachedHeaderActions()),
            ...array_values($page->getTable()->getFlatActions()),
            ...array_values($page->getTable()->getFlatBulkActions()),
        ];

        foreach ($actions as $action) {
            $suppressionsGroupees += $action instanceof DeleteBulkAction ? 1 : 0;
            $faute = panneauAutorisationsFauteDeLAction($action);

            if ($faute !== null) {
                $fautes[] = "{$ressource} : {$faute}";
            }
        }
    }

    expect($suppressionsGroupees)->toBeGreaterThanOrEqual(8)
        ->and($fautes)->toBe([]);
});

it('exige authorizeIndividualRecords() de chaque DeleteBulkAction, et authorize() de chaque action écrite dans app/', function (): void {
    $fautes = [];
    $lus = 0;

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
        $lus++;

        foreach (panneauAutorisationsFautesDuTexte($fichier->getContents()) as $faute) {
            $fautes[] = 'app/'.$fichier->getRelativePathname().':'.$faute;
        }
    }

    expect($lus)->toBeGreaterThan(100)
        ->and($fautes)->toBe([]);
});

it('repère une suppression groupée sans vérification ligne à ligne, et une action sans autorisation', function (): void {
    $source = <<<'PHP'
        <?php
        return [
            DeleteBulkAction::make()->chunkSelectedRecords(100),
            DeleteBulkAction::make()->label(fn () => $x->authorizeIndividualRecords()),
            \Filament\Actions\DeleteBulkAction::make()->authorizeIndividualRecords()->fetchSelectedRecords(false),
            DeleteBulkAction::make()->authorizeIndividualRecords()->chunkSelectedRecords(100),
            Action::make('relire')->action(fn () => null),
            Action::make('installer')->authorize(fn (): bool => true)->action(fn () => null),
            BulkAction::make('archiver')->action(fn () => null),
            CreateAction::make(),
        ];
        PHP;

    expect(panneauAutorisationsFautesDuTexte($source))->toBe([
        '3 : DeleteBulkAction sans authorizeIndividualRecords()',
        '4 : DeleteBulkAction sans authorizeIndividualRecords()',
        '5 : DeleteBulkAction qui ne lit pas ses lignes',
        '7 : Action sans authorize()',
        '9 : BulkAction sans authorize()',
    ]);
});

it('garde la vérification ligne à ligne des suppressions groupées d’une requête à l’autre, comme sous Octane', function (): void {
    expect(DeleteBulkAction::make()->shouldAuthorizeIndividualRecords())->toBeTrue();

    app()->forgetScopedInstances();
    ComponentManager::resolveScoped();

    expect(DeleteBulkAction::make()->shouldAuthorizeIndividualRecords())->toBeTrue()
        ->and(ForceDeleteBulkAction::make()->shouldAuthorizeIndividualRecords())->toBeTrue()
        ->and(RestoreBulkAction::make()->shouldAuthorizeIndividualRecords())->toBeTrue();
});

it('fait lever, plutôt qu’autoriser, une ressource dont la policy manque', function (): void {
    $administrateur = FilamentAdminPanel::admin();
    $this->actingAs($administrateur, 'admin');
    $ressource = new class() extends Resource
    {
        protected static ?string $model = Model::class;
    };

    expect(static fn (): bool => $ressource::canDeleteAny())->toThrow(LogicException::class);
});
