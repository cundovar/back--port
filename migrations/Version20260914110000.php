<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fill DevDoc portfolio case study test sections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE projects SET
            client_problem = '<p>La création et la maintenance de parcours pédagogiques demandaient beaucoup de travail manuel : structuration des menus, rédaction des cours, revue du contenu et mise à jour technique. Les agents externes devaient aussi pouvoir accéder aux cours sans contourner les règles métier de la plateforme.</p>',
            mission = '<p>Concevoir une architecture durable où Symfony et MySQL restent la source de vérité, tout en assistant la création et la révision des cours avec des agents MCP. La mission incluait aussi la mise à disposition d’une recherche augmentée sans exposer directement la base métier.</p>',
            solution = '<p>DevDoc centralise les cours dans Symfony/MySQL et expose une interface Vue.js. Les agents Node utilisent des API authentifiées, tandis qu’un service Python avec PostgreSQL/pgvector synchronise les contenus via une outbox idempotente. Cette séparation rend la génération, la publication et la recherche plus sûres et plus faciles à maintenir.</p>',
            slug = 'devdoc'
        WHERE id = 1 AND LOWER(name) = 'devdoc'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE projects SET
            client_problem = NULL,
            mission = NULL,
            solution = NULL,
            slug = 'project-1'
        WHERE id = 1 AND LOWER(name) = 'devdoc'");
    }
}
