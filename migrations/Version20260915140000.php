<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create portfolio_quote_estimates table for quote simulator.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portfolio_quote_estimates (
            id INT AUTO_INCREMENT NOT NULL,
            service_key VARCHAR(40) NOT NULL,
            answers JSON NOT NULL,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL,
            company VARCHAR(120),
            phone VARCHAR(40),
            minimum_amount INT NOT NULL,
            maximum_amount INT NOT NULL,
            calculation_detail JSON NOT NULL,
            ai_summary LONGTEXT,
            ai_recommended_scope JSON,
            ai_missing_questions JSON,
            ai_risk_flags JSON,
            ai_source VARCHAR(16) NOT NULL DEFAULT "fallback",
            status VARCHAR(16) NOT NULL DEFAULT "new",
            notes LONGTEXT,
            created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            qualified_at DATETIME COMMENT "(DC2Type:datetime_immutable)",
            PRIMARY KEY(id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portfolio_quote_estimates');
    }
}
