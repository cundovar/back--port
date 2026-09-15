<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\QuoteEstimateValidationException;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuotePricingCatalog;
use PHPUnit\Framework\TestCase;

final class QuoteEstimateCalculatorTest extends TestCase
{
    private QuoteEstimateCalculator $calculator;
    private array $catalog;

    protected function setUp(): void
    {
        $this->calculator = new QuoteEstimateCalculator(new QuotePricingCatalog());
        $this->catalog = QuotePricingCatalog::defaultCatalog();
    }

    private function answers(array $overrides = []): array
    {
        return $overrides + [
            'offerKey' => 'site-vitrine',
            'variantKey' => 'landing-page',
            'optionKeys' => [],
            'projectStage' => 'nouveau',
            'contentReadiness' => 'pret',
            'deadline' => 'normal',
        ];
    }

    public function testVariantAloneReturnsItsCatalogRange(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers());

        self::assertSame(350, $result['minimumAmount']);
        self::assertSame(650, $result['maximumAmount']);
        self::assertNotEmpty($result['includes']);
        self::assertSame([], $result['selectedOptions']);
    }

    public function testOptionsAreAddedAndListedAsBenefits(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'variantKey' => 'wordpress-vitrine',
            'optionKeys' => ['prise-rdv', 'blog'],
        ]));

        // 600-1100 + 150-350 + 120-300
        self::assertSame(850, $result['minimumAmount']);
        self::assertSame(1750, $result['maximumAmount']);
        self::assertSame(
            ['Prise de rendez-vous en ligne', 'Espace actualités ou blog'],
            array_column($result['selectedOptions'], 'label'),
        );
    }

    public function testContentToWriteAddsTheWritingRange(): void
    {
        $withContent = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'pret']));
        $withoutContent = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'a-rediger']));

        self::assertSame($withContent['minimumAmount'] + 150, $withoutContent['minimumAmount']);
        self::assertSame($withContent['maximumAmount'] + 400, $withoutContent['maximumAmount']);
    }

    public function testContentSupplementIsSkippedForOffersWithoutEditorialContent(): void
    {
        $answers = $this->answers([
            'offerKey' => 'automatisation',
            'variantKey' => 'automatisation-ciblee',
        ]);

        $ready = $this->calculator->calculate($this->catalog, $answers);
        $toWrite = $this->calculator->calculate($this->catalog, ['contentReadiness' => 'a-rediger'] + $answers);

        self::assertSame($ready['minimumAmount'], $toWrite['minimumAmount']);
        self::assertSame($ready['maximumAmount'], $toWrite['maximumAmount']);
        self::assertFalse($ready['contentQuestion']);
    }

    public function testContentSupplementStillAppliesToOffersThatShipContent(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'offerKey' => 'refonte',
            'variantKey' => 'refonte-ciblee',
            'contentReadiness' => 'a-rediger',
        ]));

        self::assertTrue($result['contentQuestion']);
        self::assertSame('Rédaction des contenus', end($result['calculationDetail'])['label']);
    }

    public function testDoNotKnowNeverAddsASupplement(): void
    {
        $ready = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'pret']));
        $unknown = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'je-ne-sais-pas']));

        self::assertSame($ready['minimumAmount'], $unknown['minimumAmount']);
        self::assertSame($ready['maximumAmount'], $unknown['maximumAmount']);
    }

    public function testPriorityDeadlineIncreasesTheRange(): void
    {
        $normal = $this->calculator->calculate($this->catalog, $this->answers());
        $priority = $this->calculator->calculate($this->catalog, $this->answers(['deadline' => 'prioritaire']));

        self::assertGreaterThan($normal['minimumAmount'], $priority['minimumAmount']);
        self::assertSame('Délai prioritaire', end($priority['calculationDetail'])['label']);
    }

    public function testProjectStageDoesNotChangeThePrice(): void
    {
        $new = $this->calculator->calculate($this->catalog, $this->answers(['projectStage' => 'nouveau']));
        $existing = $this->calculator->calculate($this->catalog, $this->answers(['projectStage' => 'existant']));

        self::assertSame($new['minimumAmount'], $existing['minimumAmount']);
        self::assertSame($new['maximumAmount'], $existing['maximumAmount']);
    }

    public function testEditedCatalogAmountsDriveTheResult(): void
    {
        $catalog = $this->catalog;
        $catalog['offers'][0]['variants'][0]['minimumAmount'] = 1000;
        $catalog['offers'][0]['variants'][0]['maximumAmount'] = 1500;

        $result = $this->calculator->calculate($catalog, $this->answers());

        self::assertSame(1000, $result['minimumAmount']);
        self::assertSame(1500, $result['maximumAmount']);
    }

    public function testUnknownOfferIsRejected(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->calculator->calculate($this->catalog, $this->answers(['offerKey' => 'inexistant']));
    }

    public function testUnknownVariantIsRejected(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->calculator->calculate($this->catalog, $this->answers(['variantKey' => 'inexistant']));
    }

    public function testOptionFromAnotherOfferIsRejected(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->calculator->calculate($this->catalog, $this->answers(['optionKeys' => ['relances-auto']]));
    }

    public function testUnknownDeadlineIsRejected(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->calculator->calculate($this->catalog, $this->answers(['deadline' => 'hier']));
    }

    public function testSameInputProducesSameOutput(): void
    {
        $answers = $this->answers(['variantKey' => 'wordpress-avance', 'optionKeys' => ['multilingue']]);

        self::assertSame(
            $this->calculator->calculate($this->catalog, $answers),
            $this->calculator->calculate($this->catalog, $answers),
        );
    }

    public function testEveryVariantKeepsAnOrderedRange(): void
    {
        foreach ($this->catalog['offers'] as $offer) {
            foreach ($offer['variants'] as $variant) {
                $result = $this->calculator->calculate($this->catalog, $this->answers([
                    'offerKey' => $offer['key'],
                    'variantKey' => $variant['key'],
                    'optionKeys' => array_column($offer['options'], 'key'),
                    'contentReadiness' => 'a-rediger',
                    'deadline' => 'prioritaire',
                ]));

                self::assertLessThanOrEqual($result['maximumAmount'], $result['minimumAmount'], $variant['key']);
            }
        }
    }
}
