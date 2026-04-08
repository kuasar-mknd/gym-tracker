<?php

declare(strict_types=1);

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

describe('Controller', function (): void {
    describe('user', function (): void {
        it('throws RuntimeException if user is not authenticated', function (): void {
            Auth::shouldReceive('user')->once()->andReturn(null);

            $controller = new class() extends Controller
            {
                public function getUser(): \App\Models\User
                {
                    return $this->user();
                }
            };

            expect(fn () => $controller->getUser())
                ->toThrow(\RuntimeException::class, 'User not authenticated');
        });

        it('returns user if authenticated', function (): void {
            $user = User::factory()->make();
            Auth::shouldReceive('user')->once()->andReturn($user);

            $controller = new class() extends Controller
            {
                public function getUser(): \App\Models\User
                {
                    return $this->user();
                }
            };

            expect($controller->getUser())->toBe($user);
        });
    });
});
