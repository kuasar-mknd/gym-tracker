<?php

declare(strict_types=1);

use App\Actions\CreateUserAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

it('creates a new user and dispatches a registered event', function (): void {
    Event::fake();

    $action = app(CreateUserAction::class);

    $input = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
    ];

    $user = $action->execute($input);

    expect($user)->toBeInstanceOf(User::class);
    expect($user->name)->toBe('John Doe');
    expect($user->email)->toBe('john@example.com');
    expect(Hash::check('password123', $user->password))->toBeTrue();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'john@example.com',
    ]);

    Event::assertDispatched(Registered::class, function (Registered $event) use ($user): bool {
        return $event->user->id === $user->id;
    });
});
