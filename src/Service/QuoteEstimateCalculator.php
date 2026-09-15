<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\QuoteEstimateValidationException;

final class QuoteEstimateCalculator
{
    // à ajuster : bases par service (euros, placeholders réalistes)
    private const SERVICE_CATALOG = [
        'automation' => ['label' => 'Automatisation', 'baseMin' => 800, 'baseMax' => 2500],
        'ai-assistant' => ['label' => 'Assistant IA', 'baseMin' => 1200, 'baseMax' => 4000],
        'refonte' => ['label' => 'Refonte', 'baseMin' => 1500, 'baseMax' => 6000],
        'custom-tool' => ['label' => 'Outil sur mesure', 'baseMin' => 2000, 'baseMax' => 8000],
        'wordpress' => ['label' => 'WordPress', 'baseMin' => 600, 'baseMax' => 2000],
    ];

    // à ajuster : multiplicateur de complexité (appliqué à baseMin ET baseMax)
    private const COMPLEXITY_MULTIPLIERS = [
        'simple' => 1.0,
        'standard' => 1.15,
        'complexe' => 1.4,
    ];

    // à ajuster : supplément forfaitaire par intégration, plafonné
    private const INTEGRATION_UNIT_SURCHARGE = 150;   // €/intégration
    private const INTEGRATION_MAX_COUNTED = 5;        // au-delà, plafond

    // à ajuster : reprise d'un existant (dette technique à absorber)
    private const LEGACY_TAKEOVER_SURCHARGE = 500;    // €

    // à ajuster : urgence (délai compressé)
    private const URGENCY_MULTIPLIER = 1.2;

    // à ajuster : accompagnement / formation
    private const TRAINING_SURCHARGES = [
        'none' => 0,
        'light' => 300,
        'full' => 700,
    ];

    private const ROUNDING_STEP = 50;   // arrondi final au multiple de 50€

    /**
     * Calculate a quote estimate range and detail breakdown.
     *
     * @param string $serviceKey One of SERVICE_CATALOG keys
     * @param string $complexity One of COMPLEXITY_MULTIPLIERS keys
     * @param int $integrationsCount Number of integrations (0+)
     * @param bool $legacyTakeover Whether legacy system needs remediation
     * @param bool $urgency Whether expedited (higher multiplier)
     * @param string $trainingNeed One of TRAINING_SURCHARGES keys
     *
     * @return array{
     *     'minimumAmount': int,
     *     'maximumAmount': int,
     *     'calculationDetail': array[]
     * }
     *
     * @throws QuoteEstimateValidationException if serviceKey, complexity, or trainingNeed not in catalog
     */
    public function calculate(
        string $serviceKey,
        string $complexity,
        int $integrationsCount,
        bool $legacyTakeover,
        bool $urgency,
        string $trainingNeed,
    ): array {
        // Validate service key
        if (!isset(self::SERVICE_CATALOG[$serviceKey])) {
            throw new QuoteEstimateValidationException(
                sprintf("Service key '%s' not in catalog.", $serviceKey)
            );
        }

        // Validate complexity
        if (!isset(self::COMPLEXITY_MULTIPLIERS[$complexity])) {
            throw new QuoteEstimateValidationException(
                sprintf("Complexity '%s' not in catalog.", $complexity)
            );
        }

        // Validate training need
        if (!isset(self::TRAINING_SURCHARGES[$trainingNeed])) {
            throw new QuoteEstimateValidationException(
                sprintf("Training need '%s' not in catalog.", $trainingNeed)
            );
        }

        $service = self::SERVICE_CATALOG[$serviceKey];
        $min = (float) $service['baseMin'];
        $max = (float) $service['baseMax'];

        $detail = [];

        // Add base service to detail
        $detail[] = [
            'label' => sprintf('Base %s', $service['label']),
            'impactMin' => (int) $min,
            'impactMax' => (int) $max,
        ];

        // Apply complexity multiplier
        $complexityMult = self::COMPLEXITY_MULTIPLIERS[$complexity];
        if ($complexityMult !== 1.0) {
            $complexityImpactMin = $min * ($complexityMult - 1.0);
            $complexityImpactMax = $max * ($complexityMult - 1.0);
            $min *= $complexityMult;
            $max *= $complexityMult;

            $detail[] = [
                'label' => sprintf('Complexité %s (+%.0f%%)', $complexity, ($complexityMult - 1.0) * 100),
                'impactMin' => (int) round($complexityImpactMin),
                'impactMax' => (int) round($complexityImpactMax),
            ];
        }

        // Apply urgency multiplier
        if ($urgency) {
            $urgencyImpactMin = $min * (self::URGENCY_MULTIPLIER - 1.0);
            $urgencyImpactMax = $max * (self::URGENCY_MULTIPLIER - 1.0);
            $min *= self::URGENCY_MULTIPLIER;
            $max *= self::URGENCY_MULTIPLIER;

            $detail[] = [
                'label' => 'Urgence (+20%)',
                'impactMin' => (int) round($urgencyImpactMin),
                'impactMax' => (int) round($urgencyImpactMax),
            ];
        }

        // Add integration surcharges
        $countedIntegrations = min($integrationsCount, self::INTEGRATION_MAX_COUNTED);
        if ($countedIntegrations > 0) {
            $integrationTotal = $countedIntegrations * self::INTEGRATION_UNIT_SURCHARGE;
            $min += $integrationTotal;
            $max += $integrationTotal;

            $detail[] = [
                'label' => sprintf('%d intégration%s', $countedIntegrations, $countedIntegrations > 1 ? 's' : ''),
                'impactMin' => $integrationTotal,
                'impactMax' => $integrationTotal,
            ];
        }

        // Add legacy takeover surcharge
        if ($legacyTakeover) {
            $min += self::LEGACY_TAKEOVER_SURCHARGE;
            $max += self::LEGACY_TAKEOVER_SURCHARGE;

            $detail[] = [
                'label' => 'Reprise d\'existant',
                'impactMin' => self::LEGACY_TAKEOVER_SURCHARGE,
                'impactMax' => self::LEGACY_TAKEOVER_SURCHARGE,
            ];
        }

        // Add training surcharge
        $trainingSurcharge = self::TRAINING_SURCHARGES[$trainingNeed];
        if ($trainingSurcharge > 0) {
            $min += $trainingSurcharge;
            $max += $trainingSurcharge;

            $trainingLabel = match ($trainingNeed) {
                'light' => 'Accompagnement léger',
                'full' => 'Accompagnement complet',
                default => 'Accompagnement',
            };

            $detail[] = [
                'label' => $trainingLabel,
                'impactMin' => $trainingSurcharge,
                'impactMax' => $trainingSurcharge,
            ];
        }

        // Round to nearest ROUNDING_STEP
        $min = (int) round($min / self::ROUNDING_STEP) * self::ROUNDING_STEP;
        $max = (int) round($max / self::ROUNDING_STEP) * self::ROUNDING_STEP;

        // Ensure max >= min (edge case when rounding collapses range)
        if ($max < $min) {
            $max = $min + self::ROUNDING_STEP;
        }

        return [
            'minimumAmount' => $min,
            'maximumAmount' => $max,
            'calculationDetail' => $detail,
        ];
    }
}
