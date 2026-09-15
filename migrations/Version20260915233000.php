<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the 'contentQuestion' flag to the catalog already stored in the database.
 * Without it, "Avez-vous déjà vos textes et vos images ?" keeps being asked — and
 * priced — for automations and AI assistants, which ship no editorial content.
 */
final class Version20260915233000 extends AbstractMigration
{
    /** Offers that do ship editorial content; every other offer answers no. */
    private const OFFERS_WITH_CONTENT = ['site-vitrine', 'refonte'];

    public function getDescription(): string
    {
        return 'Flag which quote offers ask about editorial content.';
    }

    public function up(Schema $schema): void
    {
        $this->patchCatalog(static fn (string $key): bool => in_array($key, self::OFFERS_WITH_CONTENT, true));
    }

    public function down(Schema $schema): void
    {
        $this->patchCatalog(null);
    }

    /**
     * @param (callable(string): bool)|null $decide null removes the flag entirely
     */
    private function patchCatalog(?callable $decide): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, catalog FROM portfolio_quote_pricing');

        foreach ($rows as $row) {
            $catalog = json_decode((string) $row['catalog'], true);
            if (!is_array($catalog) || !isset($catalog['offers']) || !is_array($catalog['offers'])) {
                continue;
            }

            foreach ($catalog['offers'] as $index => $offer) {
                if (!is_array($offer) || !isset($offer['key']) || !is_string($offer['key'])) {
                    continue;
                }

                if ($decide === null) {
                    unset($catalog['offers'][$index]['contentQuestion']);
                    continue;
                }

                $catalog['offers'][$index]['contentQuestion'] = $decide($offer['key']);
            }

            $this->connection->executeStatement(
                'UPDATE portfolio_quote_pricing SET catalog = ? WHERE id = ?',
                [json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']],
            );
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
