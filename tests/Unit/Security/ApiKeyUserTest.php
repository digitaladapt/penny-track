<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiKey;
use App\Security\ApiKeyUser;
use PHPUnit\Framework\TestCase;

class ApiKeyUserTest extends TestCase
{
    public function test_defaults_to_role_user(): void
    {
        $user = new ApiKeyUser();

        $this->assertSame([ApiKey::ROLE_ADMIN], $user->getRoles());
    }

    public function test_returns_configured_roles(): void
    {
        $user = new ApiKeyUser([ApiKey::ROLE_READ_ONLY]);

        $this->assertSame([ApiKey::ROLE_READ_ONLY], $user->getRoles());
    }

    public function test_erase_credentials_is_a_no_op(): void
    {
        $user = new ApiKeyUser();

        $user->eraseCredentials();

        $this->assertSame('user', $user->getUserIdentifier());
    }
}
