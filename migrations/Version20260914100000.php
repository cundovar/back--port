<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create portfolio_contact_requests table for qualified contact form submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portfolio_contact_requests (
            id INT AUTO_INCREMENT NOT NULL,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL,
            company VARCHAR(120) NOT NULL,
            position VARCHAR(120) NOT NULL,
            mission_type VARCHAR(120) NOT NULL,
            message LONGTEXT NOT NULL,
            budget VARCHAR(120),
            timeline VARCHAR(120),
            honeypot VARCHAR(255),
            status VARCHAR(16) NOT NULL DEFAULT "new",
            created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            qualified_at DATETIME,
            notes LONGTEXT,
            PRIMARY KEY(id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_contact_requests');
    }
}
