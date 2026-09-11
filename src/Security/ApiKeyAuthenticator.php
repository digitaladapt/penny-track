<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-API-Key');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $apiKey = $request->headers->get('X-API-Key');

        if (null === $apiKey || '' === $apiKey) {
            throw new CustomUserMessageAuthenticationException('No API key provided');
        }

        $apiKeyEntity = $this->apiKeyRepository->findByKey($apiKey);

        if ($apiKeyEntity === null) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }

        $role = $apiKeyEntity->isReadOnly()
            ? [ApiKey::ROLE_READ_ONLY]
            : [ApiKey::ROLE_ADMIN];

        return new SelfValidatingPassport(new UserBadge('user', fn () => new ApiKeyUser($role)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => strtr($exception->getMessageKey(), $exception->getMessageData())],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
