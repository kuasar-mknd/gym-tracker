<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

class SocialAuthSecurityTest extends TestCase
{
    public function test_invalid_provider_returns_404(): void
    {
        $response = $this->get(route('social.redirect', ['provider' => 'invalid-provider']));

        $response->assertNotFound();
    }
}
