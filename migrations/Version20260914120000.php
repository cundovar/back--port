<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fill WordPress AI pipeline and Magicieuse portfolio case studies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE projects SET
            client_problem = '<p>La création de sites WordPress répétait les mêmes étapes manuelles : recueil du brief, choix de structure, maquette, rédaction, configuration et contrôle qualité. Cette répétition ralentissait la production et rendait les résultats dépendants du temps disponible, tandis que les contenus générés par IA devaient être encadrés pour rester exploitables.</p>',
            mission = '<p>Construire un pipeline capable de transformer un brief validé en site WordPress fonctionnel, en découpant la production en étapes explicites et en faisant collaborer des agents IA avec des workflows n8n contrôlés.</p>',
            solution = '<p>Une interface React/Vite pilote le pipeline, tandis qu’une API Fastify/Mongoose orchestre huit étapes validées : discovery, architecture, design, contenu, intégration, contrôle, préparation et déploiement. Les échanges sont validés par JSON Schema et communiquent via MCP entre les agents et n8n, ce qui rend chaque étape traçable et reprise en cas d’échec.</p>',
            slug = 'wp-site-builder-pipeline-ia-wordpress'
        WHERE id = 4 AND LOWER(name) LIKE 'wp site builder%'");

        $this->addSql("UPDATE projects SET
            client_problem = '<p>La maison d’édition avait besoin d’une boutique capable de porter son univers éditorial, sans être limitée par un thème WooCommerce standard. Il fallait conserver une administration simple pour les contenus et les commandes, tout en offrant une expérience client rapide, sur mesure et cohérente avec l’identité de la marque.</p>',
            mission = '<p>Concevoir et développer un e-commerce headless où WordPress et WooCommerce restent le back-office, tandis que le front-end propose une boutique React entièrement personnalisée.</p>',
            solution = '<p>WordPress expose contenus, produits et commandes via un plugin et une API REST sur mesure. Le front React/TypeScript gère la boutique, les fiches produit, le panier et le contact ; SCSS porte les thèmes multi-ambiance et l’intégration Instagram relie la boutique à la vie éditoriale de la marque.</p>',
            slug = 'la-magicieuse'
        WHERE id = 6 AND LOWER(name) LIKE 'la magicieuse%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE projects SET
            client_problem = NULL,
            mission = NULL,
            solution = NULL,
            slug = 'project-4'
        WHERE id = 4 AND LOWER(name) LIKE 'wp site builder%'");

        $this->addSql("UPDATE projects SET
            client_problem = NULL,
            mission = NULL,
            solution = NULL,
            slug = 'project-6'
        WHERE id = 6 AND LOWER(name) LIKE 'la magicieuse%'");
    }
}
