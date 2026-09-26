<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiKey;
use App\Security\ApiKeyUser;
use App\Security\ApiKeyUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;

class ApiKeyUserProviderTest extends TestCase
{
    public function test_load_user_by_identifier_returns_api_key_user(): void
    {
        $provider = new ApiKeyUserProvider();

        $user = $provider->loadUserByIdentifier('anything');

        $this->assertInstanceOf(ApiKeyUser::class, $user);
    }

    public function test_refresh_user_returns_fresh_api_key_user(): void
    {
        $provider = new ApiKeyUserProvider();

        $user = $provider->refreshUser(new ApiKeyUser([ApiKey::ROLE_READ_ONLY]));

        $this->assertInstanceOf(ApiKeyUser::class, $user);
        $this->assertNotSame($user->getUserIdentifier(), '');
    }

    public function test_refresh_user_rejects_foreign_user_class(): void
    {
        $provider = new ApiKeyUserProvider();

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser($this->createStub(UserInterface::class));
    }

    public function test_supports_class(): void
    {
        $provider = new ApiKeyUserProvider();

        $this->assertTrue($provider->supportsClass(ApiKeyUser::class));
        $this->assertFalse($provider->supportsClass(UserInterface::class));
    }
}
