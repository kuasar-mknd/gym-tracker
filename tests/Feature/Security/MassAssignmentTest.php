<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verified_at_cannot_be_mass_assigned(): void
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ];

        // In strict mode (dev/test), this throws an exception.
        // In production, it would silently discard the attribute.
        // Both outcomes prevent mass assignment.
        $this->expectException(MassAssignmentException::class);

        new User($userData);
    }

    public function test_create_method_throws_exception_on_mass_assignment_of_protected_field(): void
    {
        $userData = [
            'name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ];

        $this->expectException(MassAssignmentException::class);

        User::create($userData);
    }
}
