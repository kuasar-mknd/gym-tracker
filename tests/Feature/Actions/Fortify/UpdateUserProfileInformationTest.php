<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\assertNotNull;

beforeEach(function (): void {
    $this->updateProfile = app(UpdateUserProfileInformation::class);
    Notification::fake();
});

it('resets the e-mail verification and notifies the user when the e-mail changes', function (): void {
    $user = User::factory()->create([
        'name' => 'Ancien Nom',
        'email' => 'old@example.com',
    ]);

    expect($user->email_verified_at)->not->toBeNull();

    $this->updateProfile->update($user, [
        'name' => 'Nouveau Nom',
        'email' => 'new@example.com',
    ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Nouveau Nom',
        'email' => 'new@example.com',
        'email_verified_at' => null,
    ]);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('keeps the verification timestamp and sends nothing when the e-mail is unchanged', function (): void {
    $user = User::factory()->create([
        'name' => 'Ancien Nom',
        'email' => 'same@example.com',
    ]);

    $verifiedAt = $user->email_verified_at;

    $this->updateProfile->update($user, [
        'name' => 'Nouveau Nom',
        'email' => 'same@example.com',
    ]);

    $fresh = $user->fresh();
    assertNotNull($fresh, "L'utilisateur a disparu de la base apres la mise a jour du profil.");

    expect($fresh->name)->toBe('Nouveau Nom')
        ->and($fresh->email)->toBe('same@example.com')
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->email_verified_at->equalTo($verifiedAt))->toBeTrue();

    Notification::assertNothingSentTo($user);
});

it('rejects an e-mail already taken by another user and changes nothing', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create([
        'name' => 'Ancien Nom',
        'email' => 'mine@example.com',
    ]);

    expect(fn () => $this->updateProfile->update($user, [
        'name' => 'Nouveau Nom',
        'email' => 'taken@example.com',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errorBag)->toBe('updateProfileInformation')
            ->and($exception->errors())->toHaveKey('email');
    });

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Ancien Nom',
        'email' => 'mine@example.com',
    ]);

    Notification::assertNothingSentTo($user);
});

it('rejects an empty name and a malformed e-mail', function (): void {
    $user = User::factory()->create([
        'name' => 'Ancien Nom',
        'email' => 'mine@example.com',
    ]);

    expect(fn () => $this->updateProfile->update($user, [
        'name' => '',
        'email' => 'not-an-email',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKeys(['name', 'email']);
    });

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Ancien Nom',
        'email' => 'mine@example.com',
    ]);
});

it('rejects a name longer than 255 characters', function (): void {
    $user = User::factory()->create(['name' => 'Ancien Nom']);

    expect(fn () => $this->updateProfile->update($user, [
        'name' => str_repeat('a', 256),
        'email' => $user->email,
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('name');
    });

    expect($user->refresh()->name)->toBe('Ancien Nom');
});
