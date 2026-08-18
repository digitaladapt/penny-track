<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create parse_job table for async LLM receipt parsing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parse_job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, raw_text CLOB NOT NULL, status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL, max_attempts INTEGER NOT NULL, last_error CLOB DEFAULT NULL, receipt_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, CONSTRAINT FK_6E3B8C3F5C9E4BFB FOREIGN KEY (receipt_id) REFERENCES receipt (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6E3B8C3F5C9E4BFB ON parse_job (receipt_id)');
        $this->addSql('CREATE INDEX parse_job_status_idx ON parse_job (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE parse_job');
    }
}
