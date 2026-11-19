<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Pages\Dashboard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Les envois de fichiers de Livewire n'ont pas d'usage au panneau (audit, notes
 * 6.3, 6.7, wave1.39) : les appels se refusent proprement (403), pour un invité
 * comme pour un administrateur, et la route d'envoi ne sert qu'à un
 * administrateur connecté.
 */

it('refuse les appels d’envoi de Livewire à un composant sans envoi de fichier, invité compris', function (string $methode): void {
    Filament::setCurrentPanel('admin');

    Livewire::test(Notifications::class)
        ->call($methode, 'fichier', [], false)
        ->assertStatus(403);
})->with(['_startUpload', '_finishUpload', '_removeUpload', '_uploadErrored']);

it('refuse les appels d’envoi de Livewire à une page du panneau qui n’en déclare aucun', function (string $methode): void {
    $admin = FilamentAdminPanel::admin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Dashboard::class)
        ->call($methode, 'fichier', [], false)
        ->assertStatus(403);
})->with(['_startUpload', '_finishUpload', '_removeUpload', '_uploadErrored']);

it('ferme la route d’envoi à qui n’est pas administrateur connecté', function (): void {
    Storage::fake('local');
    $adresse = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], absolute: false);

    $this->postJson($adresse, ['files' => [UploadedFile::fake()->image('a.png')]])->assertStatus(401);
});

it('lit les changements du journal d’activité dans attribute_changes', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:ActivityLog', 'View:ActivityLog']);
    $user = User::factory()->create(['name' => 'Avant Le Changement']);
    $user->update(['name' => 'Après Le Changement']);
    $entree = ActivityLog::query()->where('description', 'updated')->latest('id')->firstOrFail();
    $this->actingAs($admin, 'admin');

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$entree])
        ->assertSee('Après Le Changement');
});
