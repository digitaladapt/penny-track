<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add read_only flag to api_key table (read-only API keys).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_key ADD read_only BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_key DROP COLUMN read_only');
    }
}
