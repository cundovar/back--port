<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronize portfolio quote CTAs in the content JSON payload.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE content
            SET payload = JSON_SET(
                payload,
                '$.hero.primaryHref', '/devis',
                '$.services[0].serviceKey', 'automation',
                '$.services[1].serviceKey', 'ai-assistant',
                '$.services[2].serviceKey', 'refonte',
                '$.services[3].serviceKey', 'custom-tool',
                '$.services[4].serviceKey', 'wordpress'
            ), updated_at = CURRENT_TIMESTAMP
            WHERE JSON_LENGTH(JSON_EXTRACT(payload, '$.services')) >= 5
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE content
            SET payload = JSON_REMOVE(
                JSON_SET(payload, '$.hero.primaryHref', '#contact'),
                '$.services[0].serviceKey',
                '$.services[1].serviceKey',
                '$.services[2].serviceKey',
                '$.services[3].serviceKey',
                '$.services[4].serviceKey'
            ), updated_at = CURRENT_TIMESTAMP
            WHERE JSON_LENGTH(JSON_EXTRACT(payload, '$.services')) >= 5
            SQL);
    }
}
