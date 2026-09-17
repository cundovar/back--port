<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuotePricingCatalog;
use PHPUnit\Framework\TestCase;

final class QuoteStackCatalogTest extends TestCase
{
    public function testTheDefaultListNamesTheCasesAClientCanActuallyBeIn(): void
    {
        $keys = array_column(QuotePricingCatalog::defaultCatalog()['stacks'], 'key');

        self::assertSame(['wordpress', 'constructeur', 'sur-mesure', 'genere-ia', 'inconnu'], $keys);
    }

    /**
     * The grid saved in production predates this list. Without the fallback the
     * question would never appear until someone re-saved the grid by hand.
     */
    public function testAGridSavedBeforeThisListStillGetsTheDefaultOne(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        unset($catalog['stacks']);

        $normalised = QuotePricingCatalog::withDefaults($catalog);

        self::assertNotEmpty($normalised['stacks']);
        self::assertSame('wordpress', $normalised['stacks'][0]['key']);
    }

    public function testAnEmptyListFallsBackToo(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['stacks'] = [];

        self::assertNotEmpty(QuotePricingCatalog::withDefaults($catalog)['stacks']);
    }

    public function testAStackCarryingAnAmountIsRefused(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['stacks'][] = ['key' => 'payant', 'label' => 'Payant', 'minimumAmount' => 100];

        $paths = array_column($this->service()->validate($catalog), 'path');

        self::assertContains('stacks.5.minimumAmount', $paths);
    }

    public function testADuplicateKeyIsRefused(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['stacks'][] = ['key' => 'wordpress', 'label' => 'Encore WordPress'];

        $paths = array_column($this->service()->validate($catalog), 'path');

        self::assertContains('stacks.5.key', $paths);
    }

    public function testASubmittedKeyResolvesToItsLabel(): void
    {
        $resolved = $this->service()->resolveStack(QuotePricingCatalog::defaultCatalog(), 'genere-ia');

        self::assertSame('genere-ia', $resolved['key']);
        self::assertStringContainsString('Lovable', $resolved['label']);
    }

    /**
     * A stale browser tab must never block the journey, exactly like the tools:
     * an unknown or missing key is dropped, not refused.
     */
    public function testAnUnknownOrMissingKeyIsIgnoredRatherThanRefused(): void
    {
        $service = $this->service();
        $catalog = QuotePricingCatalog::defaultCatalog();

        foreach ([null, '', 'inexistant', ['tableau'], 42] as $submitted) {
            self::assertSame(['key' => '', 'label' => ''], $service->resolveStack($catalog, $submitted));
        }
    }

    private function service(): QuotePricingCatalog
    {
        return new QuotePricingCatalog();
    }
}
