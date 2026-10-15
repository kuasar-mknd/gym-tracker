<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentUsersTable extends TableWidget
{
    #[\Override]
    protected static ?int $sort = 3;

    #[\Override]
    protected int|string|array $columnSpan = 'full';

    /**
     * Les dix derniers inscrits parmi les comptes ouverts, par date
     * d'inscription. Un compte encore en attente du lien n'y figure pas
     * (`User::ouverts()`) : il ne se connecte pas, s'efface s'il n'est jamais
     * ouvert, et son adresse peut être celle d'un tiers. La liste des
     * utilisateurs le montre, avec sa colonne et son filtre « En attente du
     * lien », et la tuile « Comptes » dit combien attendent.
     *
     * Sans titre posé, Filament tirait du nom de la classe « Recent Users
     * Table », en anglais. Le titre dit ce que l'encart montre, et dans quel
     * ordre : des comptes ouverts, rangés par date d'inscription. Rien ne
     * garde le moment où un compte s'ouvre (l'ouverture efface
     * `en_attente_depuis`) : un compte inscrit il y a trois jours et ouvert à
     * l'instant reste à sa date d'inscription, pas en tête. La description
     * redit l'ordre, et dit où sont les comptes en attente.
     */
    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading('Derniers inscrits parmi les comptes ouverts')
            ->description("Classés par date d'inscription ; les comptes en attente du lien sont dans la liste des utilisateurs.")
            ->query(User::ouverts()->latest()->limit(10))
            ->columns([
                // Pas de `searchable()` : chaque frappe declenchait deux
                // parcours complets de `users` — la page et son COUNT — pour un
                // `LIKE '%…%'` qu'aucun index B-tree ne sert. Un encart de dix
                // inscrits recents n'a pas de recherche a offrir.
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('email')->label('Adresse e-mail'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Inscription'),
                TextColumn::make('last_workout_at')
                    ->dateTime()
                    ->label('Dernière activité'),
            ]);
    }
}
