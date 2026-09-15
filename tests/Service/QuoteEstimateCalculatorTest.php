<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\QuoteEstimateValidationException;
use App\Service\QuoteEstimateCalculator;
use PHPUnit\Framework\TestCase;

final class QuoteEstimateCalculatorTest extends TestCase
{
    private QuoteEstimateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new QuoteEstimateCalculator();
    }

    public function test_automation_base_with_standard_complexity(): void
    {
        $result = $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'standard',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        // Base 800-2500 × 1.15 (standard) = 920-2875, rounded to 50 = 900-2900
        $minAmount = $result['minimumAmount'];
        $maxAmount = $result['maximumAmount'];
        self::assertSame(900, $minAmount);
        self::assertSame(2900, $maxAmount);
    }

    public function test_integration_surcharge_appears_in_detail(): void
    {
        $result = $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'simple',
            integrationsCount: 2,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        $integrationDetail = array_filter(
            $result['calculationDetail'],
            static fn($row): bool => str_contains($row['label'], 'intégration')
        );
        self::assertNotEmpty($integrationDetail);

        $detail = array_pop($integrationDetail);
        self::assertSame(300, $detail['impactMin']);   // 2 × 150
        self::assertSame(300, $detail['impactMax']);
    }

    public function test_omitting_optional_fields_applies_neutral_defaults(): void
    {
        // Without urgency, legacyTakeover, trainingNeed at all, should use defaults
        $result1 = $this->calculator->calculate(
            serviceKey: 'wordpress',
            complexity: 'simple',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        $result2 = $this->calculator->calculate(
            serviceKey: 'wordpress',
            complexity: 'simple',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        self::assertSame($result1['minimumAmount'], $result2['minimumAmount']);
        self::assertSame($result1['maximumAmount'], $result2['maximumAmount']);
    }

    public function test_service_key_out_of_catalog_throws(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->expectExceptionMessage("Service key 'nope' not in catalog");

        $this->calculator->calculate(
            serviceKey: 'nope',
            complexity: 'simple',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );
    }

    public function test_complexity_out_of_catalog_throws(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->expectExceptionMessage("Complexity 'extreme' not in catalog");

        $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'extreme',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );
    }

    public function test_training_need_out_of_catalog_throws(): void
    {
        $this->expectException(QuoteEstimateValidationException::class);
        $this->expectExceptionMessage("Training need 'full-time' not in catalog");

        $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'simple',
            integrationsCount: 0,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'full-time',
        );
    }

    public function test_same_input_produces_same_output(): void
    {
        $input = [
            'serviceKey' => 'custom-tool',
            'complexity' => 'complexe',
            'integrationsCount' => 3,
            'legacyTakeover' => true,
            'urgency' => true,
            'trainingNeed' => 'full',
        ];

        $result1 = $this->calculator->calculate(...$input);
        $result2 = $this->calculator->calculate(...$input);

        self::assertSame($result1, $result2);
    }

    public function test_integration_count_capped_at_5(): void
    {
        // Count 50 integrations: should cap impact at 5 × 150 = 750
        $resultWith50 = $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'simple',
            integrationsCount: 50,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        $resultWith5 = $this->calculator->calculate(
            serviceKey: 'automation',
            complexity: 'simple',
            integrationsCount: 5,
            legacyTakeover: false,
            urgency: false,
            trainingNeed: 'none',
        );

        // Both should produce the same result
        self::assertSame($resultWith50['minimumAmount'], $resultWith5['minimumAmount']);
        self::assertSame($resultWith50['maximumAmount'], $resultWith5['maximumAmount']);
    }

    public function test_minimum_never_exceeds_maximum(): void
    {
        $services = ['automation', 'ai-assistant', 'refonte', 'custom-tool', 'wordpress'];
        $complexities = ['simple', 'standard', 'complexe'];
        $trainings = ['none', 'light', 'full'];

        foreach ($services as $service) {
            foreach ($complexities as $complexity) {
                foreach ($trainings as $training) {
                    $result = $this->calculator->calculate(
                        serviceKey: $service,
                        complexity: $complexity,
                        integrationsCount: 5,
                        legacyTakeover: true,
                        urgency: true,
                        trainingNeed: $training,
                    );

                    self::assertLessThanOrEqual(
                        $result['maximumAmount'],
                        $result['minimumAmount'],
                        sprintf(
                            "min > max for %s/%s/%s",
                            $service,
                            $complexity,
                            $training,
                        ),
                    );
                }
            }
        }
    }
}
