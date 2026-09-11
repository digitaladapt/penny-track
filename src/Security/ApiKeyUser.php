<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class ApiKeyUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly array $roles = ['ROLE_USER'],
    ) {
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'user';
    }
}
