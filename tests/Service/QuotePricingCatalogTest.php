<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuotePricingCatalog;
use PHPUnit\Framework\TestCase;

final class QuotePricingCatalogTest extends TestCase
{
    private QuotePricingCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new QuotePricingCatalog();
    }

    public function testDefaultCatalogHasFiveOfferFamilies(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();

        self::assertCount(5, $catalog['offers']);
        self::assertSame([], $this->catalog->validate($catalog));
    }

    public function testDefaultCatalogExposesNoTechnicalJargon(): void
    {
        $serialized = strtolower(json_encode(QuotePricingCatalog::defaultCatalog(), JSON_THROW_ON_ERROR));

        foreach (['complexit', 'base de donn', '"bdd', 'integrationscount'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function testDefaultVariantAmountsMatchTheAgreedGrid(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();

        $expected = [
            'landing-page' => [550, 550],
            'wordpress-vitrine' => [900, 900],
            'wordpress-avance' => [1400, 1400],
            'automatisation-ciblee' => [500, 500],
            'automatisation-multi-outils' => [900, 900],
            'assistant-initial' => [750, 750],
            'assistant-connecte' => [1300, 1300],
            'refonte-ciblee' => [400, 900],
            'outil-mvp' => [1200, 2800],
        ];

        $found = [];
        foreach ($catalog['offers'] as $offer) {
            foreach ($offer['variants'] as $variant) {
                $found[$variant['key']] = [$variant['minimumAmount'], $variant['maximumAmount']];
            }
        }

        self::assertSame($expected, $found);
    }

    public function testMinimumAboveMaximumIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][0]['minimumAmount'] = 9000;

        $errors = $this->catalog->validate($catalog);

        self::assertNotEmpty($errors);
        self::assertSame('offers.0.variants.0.maximumAmount', $errors[0]['path']);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['options'][0]['minimumAmount'] = -50;

        self::assertNotEmpty($this->catalog->validate($catalog));
    }

    public function testDuplicateVariantKeyIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][1]['key'] = $catalog['offers'][0]['variants'][0]['key'];

        $errors = $this->catalog->validate($catalog);

        self::assertNotEmpty($errors);
        self::assertSame('Clé en double.', $errors[0]['message']);
    }

    public function testEmptyOffersAreRejected(): void
    {
        self::assertNotEmpty($this->catalog->validate(['offers' => []]));
    }

    public function testEveryDefaultVariantDeclaresAModeAndAPrioritySupplement(): void
    {
        foreach (QuotePricingCatalog::defaultCatalog()['offers'] as $offer) {
            foreach ($offer['variants'] as $variant) {
                self::assertContains($variant['pricingMode'], QuotePricingCatalog::PRICING_MODES, $variant['key']);
                self::assertIsInt($variant['priorityAmount'], $variant['key']);
            }
        }
    }

    public function testAnUnknownPricingModeIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][0]['pricingMode'] = 'negociable';

        self::assertContains(
            'offers.0.variants.0.pricingMode',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testACommittedVariantCannotCarryTwoDifferentBounds(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][0]['maximumAmount'] = 2000;

        self::assertContains(
            'offers.0.variants.0.maximumAmount',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testAnOfferWithACommittedVariantRefusesARangedOption(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['options'][0]['maximumAmount'] = 900;

        // A fixed pack plus a 250-900 option would silently become a range again.
        self::assertContains(
            'offers.0.options.0.maximumAmount',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testAnOfferPricedAsARangeStillAcceptsRangedOptions(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $refonte = null;
        foreach ($catalog['offers'] as $index => $offer) {
            if ($offer['key'] === 'refonte') {
                $refonte = $index;
            }
        }

        self::assertNotNull($refonte);
        self::assertSame([], $this->catalog->validate($catalog));
        self::assertNotSame(
            $catalog['offers'][$refonte]['options'][0]['minimumAmount'],
            $catalog['offers'][$refonte]['options'][0]['maximumAmount'],
        );
    }

    public function testAMissingPrioritySupplementIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        unset($catalog['offers'][0]['variants'][0]['priorityAmount']);

        self::assertContains(
            'offers.0.variants.0.priorityAmount',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testToolsAreListedWithoutAnyAmount(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        self::assertNotEmpty($catalog['tools']);
        self::assertSame([], $this->catalog->validate($catalog));

        $catalog['tools'][0]['minimumAmount'] = 100;

        self::assertContains(
            'tools.0.minimumAmount',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testDuplicateToolKeysAreRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['tools'][] = $catalog['tools'][0];

        self::assertNotEmpty($this->catalog->validate($catalog));
    }

    public function testLookupHelpersResolveKnownKeysOnly(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();

        $offer = $this->catalog->findOffer($catalog, 'automatisation');
        self::assertNotNull($offer);
        self::assertNotNull($this->catalog->findVariant($offer, 'automatisation-ciblee'));
        self::assertNull($this->catalog->findVariant($offer, 'inexistant'));
        self::assertNull($this->catalog->findOffer($catalog, 'inexistant'));
    }

    public function testNormalizeSurvivesAMalformedDraft(): void
    {
        $malformed = [
            'offers' => ['not-an-array', ['key' => 'x', 'variants' => 'nope', 'options' => 5]],
            'adjustments' => 'nope',
        ];

        $normalized = $this->catalog->normalize($malformed);

        // No fatal here: the draft must reach validate() and come back as a 422.
        self::assertNotEmpty($this->catalog->validate($normalized));
    }

    public function testNormalizeHandlesOffersSentAsAJsonObject(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'] = ['b' => $catalog['offers'][0]];

        $normalized = $this->catalog->normalize($catalog);

        self::assertArrayHasKey(0, $normalized['offers']);
        self::assertSame([], $this->catalog->validate($normalized));
    }

    public function testAnAdjustmentWithoutALabelIsRejected(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['adjustments']['contentWriting']['label'] = '   ';

        self::assertContains(
            'adjustments.contentWriting.label',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testContentQuestionMustStayABoolean(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['contentQuestion'] = 'oui';

        self::assertContains(
            'offers.0.contentQuestion',
            array_column($this->catalog->validate($catalog), 'path'),
        );
    }

    public function testEveryDefaultOfferDeclaresWhetherItShipsContent(): void
    {
        foreach (QuotePricingCatalog::defaultCatalog()['offers'] as $offer) {
            self::assertIsBool($offer['contentQuestion'], $offer['key']);
        }
    }
}
