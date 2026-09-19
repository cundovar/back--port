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

    public function testFixedVariantAloneReturnsOneCommittedAmount(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers());

        self::assertSame('fixed', $result['pricingMode']);
        self::assertSame(550, $result['minimumAmount']);
        self::assertSame(550, $result['maximumAmount']);
        self::assertSame('Prix ferme pour le périmètre décrit ci-dessus.', $result['disclaimer']);
        self::assertNotEmpty($result['includes']);
        self::assertSame([], $result['selectedOptions']);
    }

    public function testRangeVariantKeepsBothBoundsAndTheIndicativeWording(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'offerKey' => 'refonte',
            'variantKey' => 'refonte-ciblee',
        ]));

        self::assertSame('range', $result['pricingMode']);
        self::assertSame(400, $result['minimumAmount']);
        self::assertSame(900, $result['maximumAmount']);
        self::assertStringContainsString('indicative', $result['disclaimer']);
    }

    public function testFromVariantCommitsToItsStartingAmount(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'variantKey' => 'wordpress-avance',
        ]));

        self::assertSame('from', $result['pricingMode']);
        self::assertSame(1400, $result['minimumAmount']);
        self::assertSame(1400, $result['maximumAmount']);
        self::assertStringContainsString('Prix de départ', $result['disclaimer']);
    }

    public function testACommittedPackStaysCommittedWithOptionsContentAndPriority(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'variantKey' => 'wordpress-vitrine',
            'optionKeys' => ['prise-rdv', 'blog'],
            'contentReadiness' => 'a-rediger',
            'deadline' => 'prioritaire',
        ]));

        // 900 + 250 + 200 + 300 (rédaction) + 200 (urgence) — et toujours un seul montant.
        self::assertSame(1850, $result['minimumAmount']);
        self::assertSame($result['minimumAmount'], $result['maximumAmount']);
    }

    public function testOptionsAreAddedAndListedAsBenefits(): void
    {
        $result = $this->calculator->calculate($this->catalog, $this->answers([
            'variantKey' => 'wordpress-vitrine',
            'optionKeys' => ['prise-rdv', 'blog'],
        ]));

        // 900 + 250 + 200, prix ferme
        self::assertSame(1350, $result['minimumAmount']);
        self::assertSame(1350, $result['maximumAmount']);
        self::assertSame(
            ['Prise de rendez-vous en ligne', 'Espace actualités ou blog'],
            array_column($result['selectedOptions'], 'label'),
        );
    }

    public function testContentToWriteAddsTheWritingRange(): void
    {
        $withContent = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'pret']));
        $withoutContent = $this->calculator->calculate($this->catalog, $this->answers(['contentReadiness' => 'a-rediger']));

        self::assertSame($withContent['minimumAmount'] + 300, $withoutContent['minimumAmount']);
        self::assertSame($withContent['maximumAmount'] + 300, $withoutContent['maximumAmount']);
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

    public function testPriorityDeadlineAddsTheVariantFlatSupplement(): void
    {
        $normal = $this->calculator->calculate($this->catalog, $this->answers());
        $priority = $this->calculator->calculate($this->catalog, $this->answers(['deadline' => 'prioritaire']));

        // landing-page carries priorityAmount 150, applied identically to both bounds.
        self::assertSame($normal['minimumAmount'] + 150, $priority['minimumAmount']);
        self::assertSame($normal['maximumAmount'] + 150, $priority['maximumAmount']);
        self::assertSame('Délai prioritaire', end($priority['calculationDetail'])['label']);
    }

    public function testPrioritySupplementIsTakenFromTheSelectedVariant(): void
    {
        $small = $this->calculator->calculate($this->catalog, $this->answers(['deadline' => 'prioritaire']));
        $large = $this->calculator->calculate($this->catalog, $this->answers([
            'offerKey' => 'outil-metier',
            'variantKey' => 'outil-mvp',
            'deadline' => 'prioritaire',
        ]));

        self::assertSame(150, end($small['calculationDetail'])['impactMin']);
        self::assertSame(500, end($large['calculationDetail'])['impactMin']);
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
        $catalog['offers'][0]['variants'][0]['maximumAmount'] = 1000;

        $result = $this->calculator->calculate($catalog, $this->answers());

        self::assertSame(1000, $result['minimumAmount']);
        self::assertSame(1000, $result['maximumAmount']);
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
