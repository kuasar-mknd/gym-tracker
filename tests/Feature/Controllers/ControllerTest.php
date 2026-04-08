<?php

declare(strict_types=1);

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

describe('Controller', function (): void {
    describe('user', function (): void {
        it('throws a RuntimeException if no user is authenticated', function (): void {
            // Create an anonymous class extending Controller to access the protected user() method
            $controller = new class extends Controller {
                public function callUser(): User
                {
                    return $this->user();
                }
            };

            // Ensure no user is authenticated
            Auth::logout();

            // Assert that calling the method throws the expected exception
            expect(fn (): User => $controller->callUser())
                ->toThrow(\RuntimeException::class, 'User not authenticated');
        });

        it('returns the authenticated user if one exists', function (): void {
            // Create an anonymous class extending Controller to access the protected user() method
            $controller = new class extends Controller {
                public function callUser(): User
                {
                    return $this->user();
                }
            };

            // Authenticate a user
            $user = User::factory()->create();
            Auth::login($user);

            // Assert that the method returns the authenticated user
            expect($controller->callUser()->id)->toBe($user->id);
        });
    });
});
