<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\QuotePricingCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the stored catalog to the pack contract: every variant gains a
 * 'pricingMode' and a flat 'priorityAmount', the catalog gains the list of tools
 * offered in the form, and the global priority multiplier disappears.
 *
 * Amounts are deliberately taken from the seed catalog: the grid in production is
 * still the seeded one, and any later editorial change is made from the backoffice.
 */
final class Version20260916090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pricing modes, flat priority supplements and tools to the quote catalog.';
    }

    public function up(Schema $schema): void
    {
        $reference = QuotePricingCatalog::defaultCatalog();

        $this->patch(static function (array $catalog) use ($reference): array {
            $catalog['tools'] = $reference['tools'];

            foreach ($catalog['offers'] ?? [] as $offerIndex => $offer) {
                $referenceOffer = self::findByKey($reference['offers'], $offer['key'] ?? null);

                foreach ($offer['variants'] ?? [] as $variantIndex => $variant) {
                    $referenceVariant = $referenceOffer === null
                        ? null
                        : self::findByKey($referenceOffer['variants'], $variant['key'] ?? null);

                    // A variant unknown to the seed keeps its bounds as an honest range.
                    $catalog['offers'][$offerIndex]['variants'][$variantIndex]['pricingMode']
                        = $referenceVariant['pricingMode'] ?? QuotePricingCatalog::MODE_RANGE;
                    $catalog['offers'][$offerIndex]['variants'][$variantIndex]['priorityAmount']
                        = $referenceVariant['priorityAmount'] ?? 0;

                    foreach (['minimumAmount', 'maximumAmount'] as $field) {
                        if (isset($referenceVariant[$field])) {
                            $catalog['offers'][$offerIndex]['variants'][$variantIndex][$field] = $referenceVariant[$field];
                        }
                    }
                }

                foreach ($offer['options'] ?? [] as $optionIndex => $option) {
                    $referenceOption = $referenceOffer === null
                        ? null
                        : self::findByKey($referenceOffer['options'], $option['key'] ?? null);

                    foreach (['minimumAmount', 'maximumAmount'] as $field) {
                        if (isset($referenceOption[$field])) {
                            $catalog['offers'][$offerIndex]['options'][$optionIndex][$field] = $referenceOption[$field];
                        }
                    }
                }
            }

            unset($catalog['adjustments']['priorityDelay']);
            $catalog['adjustments']['contentWriting'] = $reference['adjustments']['contentWriting'];

            return $catalog;
        });
    }

    public function down(Schema $schema): void
    {
        $this->patch(static function (array $catalog): array {
            foreach ($catalog['offers'] ?? [] as $offerIndex => $offer) {
                foreach ($offer['variants'] ?? [] as $variantIndex => $variant) {
                    unset(
                        $catalog['offers'][$offerIndex]['variants'][$variantIndex]['pricingMode'],
                        $catalog['offers'][$offerIndex]['variants'][$variantIndex]['priorityAmount'],
                    );
                }
            }

            unset($catalog['tools']);
            $catalog['adjustments']['priorityDelay'] = ['label' => 'Délai prioritaire', 'multiplier' => 1.25];

            return $catalog;
        });
    }

    /**
     * @param callable(array): array $transform
     */
    private function patch(callable $transform): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, catalog FROM portfolio_quote_pricing') as $row) {
            $catalog = json_decode((string) $row['catalog'], true);
            if (!is_array($catalog)) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE portfolio_quote_pricing SET catalog = ? WHERE id = ?',
                [json_encode($transform($catalog), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']],
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function findByKey(array $items, mixed $key): ?array
    {
        foreach ($items as $item) {
            if (is_array($item) && ($item['key'] ?? null) === $key) {
                return $item;
            }
        }

        return null;
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
