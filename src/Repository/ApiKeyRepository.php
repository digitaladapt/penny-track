<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * True when at least one full-access (admin) key exists.
     *
     * Read-only keys don't count: a user who only created a read-only key
     * (e.g. via the CLI) must still be able to bootstrap an admin key via
     * the web setup flow.
     */
    public function hasAdminKey(): bool
    {
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.readOnly = :readOnly')
            ->setParameter('readOnly', false)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Find the stored API key whose bcrypt hash matches the provided key.
     *
     * There may be several keys (admin + read-only), so iterate over all of
     * them. password_verify is constant-time for the given input.
     */
    public function findByKey(string $apiKey): ?ApiKey
    {
        foreach ($this->findAll() as $candidate) {
            if ($candidate->getKeyHash() !== null && password_verify($apiKey, $candidate->getKeyHash())) {
                return $candidate;
            }
        }

        return null;
    }
}
