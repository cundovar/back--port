<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portfolio case study fields to projects only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD slug VARCHAR(180) NOT NULL DEFAULT \'\', ADD client_problem LONGTEXT DEFAULT NULL, ADD mission LONGTEXT DEFAULT NULL, ADD solution LONGTEXT DEFAULT NULL, ADD outcomes JSON DEFAULT NULL, ADD service_tags JSON DEFAULT NULL, ADD featured TINYINT(1) NOT NULL DEFAULT 0, ADD sort_order INT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE projects SET slug = CONCAT(\'project-\', id) WHERE slug = \'\'');
        $this->addSql('UPDATE projects SET status = \'in_progress\' WHERE status = \'wip\'');
        $this->addSql('UPDATE projects SET outcomes = JSON_ARRAY() WHERE outcomes IS NULL');
        $this->addSql('UPDATE projects SET service_tags = JSON_ARRAY() WHERE service_tags IS NULL');
        $this->addSql('ALTER TABLE projects MODIFY outcomes JSON NOT NULL, MODIFY service_tags JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE projects SET status = \'wip\' WHERE status = \'in_progress\'');
        $this->addSql('ALTER TABLE projects DROP slug, DROP client_problem, DROP mission, DROP solution, DROP outcomes, DROP service_tags, DROP featured, DROP sort_order');
    }
}
