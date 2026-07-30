<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Receipt;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class ReceiptTest extends TestCase
{
    public function testReceiptCreation(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('45.50');
        $receipt->setBusiness('Chipotle');
        $receipt->setCategory('Food');
        $receipt->setLocation('Downtown');
        $receipt->setTags(['lunch', 'work']);
        $receipt->setNotes('Team lunch');

        $this->assertSame('45.50', $receipt->getAmount());
        $this->assertSame('Chipotle', $receipt->getBusiness());
        $this->assertSame('Food', $receipt->getCategory());
        $this->assertSame('Downtown', $receipt->getLocation());
        $this->assertSame(['lunch', 'work'], $receipt->getTags());
        $this->assertSame('Team lunch', $receipt->getNotes());
    }

    public function testTagsDefaultToEmptyArray(): void
    {
        $receipt = new Receipt();
        $this->assertSame([], $receipt->getTags());
    }

    public function testValidationFailsWithoutRequiredFields(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $receipt = new Receipt();
        $errors = $validator->validate($receipt);

        $this->assertGreaterThan(0, count($errors));
    }

    public function testValidationPassesWithRequiredFields(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test Store');
        $receipt->setCategory('Food');

        $errors = $validator->validate($receipt);
        $this->assertCount(0, $errors);
    }

    public function testValidationFailsWithNegativeAmount(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $receipt = new Receipt();
        $receipt->setAmount('-5.00');
        $receipt->setBusiness('Test Store');
        $receipt->setCategory('Food');

        $errors = $validator->validate($receipt);
        $this->assertGreaterThan(0, count($errors));
    }

    public function testLifecycleCallbacksSetTimestamps(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Food');

        $receipt->onPrePersist();

        $this->assertNotNull($receipt->getCreatedAt());
        $this->assertNotNull($receipt->getUpdatedAt());

        $originalUpdated = $receipt->getUpdatedAt();
        sleep(1);
        $receipt->onPreUpdate();

        $this->assertGreaterThan($originalUpdated, $receipt->getUpdatedAt());
    }

    public function testPrePersistDoesNotOverwriteExistingCreatedAt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Food');

        $customDate = new \DateTimeImmutable('2023-01-15 14:30:00');
        $receipt->setCreatedAt($customDate);

        $receipt->onPrePersist();

        $this->assertSame($customDate, $receipt->getCreatedAt());
        $this->assertNotNull($receipt->getUpdatedAt());
    }
}
