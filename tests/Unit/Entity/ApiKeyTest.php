<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiKey;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ApiKeyTest extends TestCase
{
    public function test_default_state(): void
    {
        $apiKey = new ApiKey();

        $this->assertNull($apiKey->getId());
        $this->assertNull($apiKey->getKeyHash());
        $this->assertFalse($apiKey->isReadOnly());
        $this->assertNull($apiKey->getCreatedAt());
    }

    public function test_setters_are_chainable_and_round_trip(): void
    {
        $apiKey = new ApiKey();
        $createdAt = new DateTimeImmutable('2025-02-03 04:05:06');

        $result = $apiKey
            ->setKeyHash('hashed-value')
            ->setReadOnly(true)
            ->setCreatedAt($createdAt);

        $this->assertSame($apiKey, $result);
        $this->assertSame('hashed-value', $apiKey->getKeyHash());
        $this->assertTrue($apiKey->isReadOnly());
        $this->assertSame($createdAt, $apiKey->getCreatedAt());
    }

    public function test_pre_persist_sets_created_at(): void
    {
        $apiKey = new ApiKey();

        $apiKey->onPrePersist();

        $this->assertInstanceOf(DateTimeImmutable::class, $apiKey->getCreatedAt());
    }
}
