<?php

declare(strict_types=1);

use App\Filament\Resources\ErreursNavigateur\Pages\ListErreursNavigateur;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le texte que la page envoie (message, adresse, navigateur) s'affiche tel
 * quel, jamais en HTML : une colonne qui l'interpréterait en ferait un script
 * dans le panneau. Le test garde les colonnes ; les infobulles, qui rendent
 * des textes (closures typées `string`), ne sont pas vérifiées ici.
 */
it('n’interprète en HTML aucune colonne ni infobulle du texte gardé', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:ErreurNavigateur', 'View:ErreurNavigateur']), 'admin');

    /** @var ListErreursNavigateur $page */
    $page = Livewire::test(ListErreursNavigateur::class)->instance();
    $colonnes = $page->getTable()->getColumns();

    foreach (['message', 'source', 'url', 'agent'] as $nom) {
        $colonne = $colonnes[$nom] ?? null;

        expect($colonne instanceof TextColumn && ! $colonne->isHtml())->toBeTrue($nom);
    }
});
