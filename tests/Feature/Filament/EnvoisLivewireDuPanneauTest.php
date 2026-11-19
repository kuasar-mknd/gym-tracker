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

/**
 * @param  array<string, string>  $parametres
 */
function adresseSigneeDEnvoi(string $route, array $parametres = []): string
{
    return URL::temporarySignedRoute($route, now()->addMinutes(5), $parametres, absolute: false);
}

it('ouvre la route d’envoi à un administrateur connecté (témoin)', function (): void {
    Storage::fake('tmp-for-tests');
    $admin = FilamentAdminPanel::admin();

    $this->actingAs($admin, 'admin')
        ->withSession(['_token' => 'jeton'])
        ->postJson(adresseSigneeDEnvoi('livewire.upload-file'), ['files' => [UploadedFile::fake()->image('a.png')]], ['X-CSRF-TOKEN' => 'jeton'])
        ->assertOk()
        ->assertJsonStructure(['paths']);
});

it('ferme la route d’envoi à qui n’est pas administrateur connecté', function (): void {
    Storage::fake('local');

    $this->postJson(adresseSigneeDEnvoi('livewire.upload-file'), ['files' => [UploadedFile::fake()->image('a.png')]])
        ->assertUnauthorized();
});

it('ferme la route d’aperçu à qui n’est pas administrateur connecté', function (): void {
    $this->getJson(adresseSigneeDEnvoi('livewire.preview-file', ['filename' => 'a.png']))->assertUnauthorized();
});

it('ferme la route d’envoi hors de la liste d’adresses, en production', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $admin = FilamentAdminPanel::admin();

    $this->actingAs($admin, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withSession(['_token' => 'jeton'])
        ->postJson(adresseSigneeDEnvoi('livewire.upload-file'), ['files' => [UploadedFile::fake()->image('a.png')]], ['X-CSRF-TOKEN' => 'jeton'])
        ->assertNotFound();
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
