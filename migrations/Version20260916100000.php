<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The previous migration changes the active pricing contract. Incrementing the
 * stored version keeps estimates created before and after that change distinct.
 */
final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increment the quote pricing version after migrating the catalog contract.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE portfolio_quote_pricing SET version = version + 1, updated_at = CURRENT_TIMESTAMP');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE portfolio_quote_pricing SET version = GREATEST(version - 1, 1), updated_at = CURRENT_TIMESTAMP');
    }
}
