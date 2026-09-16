<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\QuoteEstimateValidationException;

class QuoteEstimateCalculator
{
    private const ROUNDING_STEP = 50;

    public const DEADLINE_FLEXIBLE = 'flexible';
    public const DEADLINE_NORMAL = 'normal';
    public const DEADLINE_PRIORITAIRE = 'prioritaire';

    public const CONTENT_READY = 'pret';
    public const CONTENT_TO_WRITE = 'a-rediger';
    public const CONTENT_UNKNOWN = 'je-ne-sais-pas';

    public const STAGE_NEW = 'nouveau';
    public const STAGE_EXISTING = 'existant';

    private const PRIORITY_LABEL = 'Délai prioritaire';

    /** What the visitor is legally told, per commercial mode of the variant. */
    private const DISCLAIMERS = [
        QuotePricingCatalog::MODE_FIXED => 'Prix ferme pour le périmètre décrit ci-dessus.',
        QuotePricingCatalog::MODE_FROM => 'Prix de départ pour le périmètre décrit ; ce qui sera ajouté ensemble est chiffré à part.',
        QuotePricingCatalog::MODE_RANGE => 'Estimation indicative, non contractuelle. Ce montant situe l’ordre de grandeur de votre projet ; il ne remplace pas un devis.',
    ];

    private const DEADLINES = [self::DEADLINE_FLEXIBLE, self::DEADLINE_NORMAL, self::DEADLINE_PRIORITAIRE];
    private const CONTENT_STATES = [self::CONTENT_READY, self::CONTENT_TO_WRITE, self::CONTENT_UNKNOWN];
    private const STAGES = [self::STAGE_NEW, self::STAGE_EXISTING];

    public function __construct(private readonly QuotePricingCatalog $catalogService)
    {
    }

    /**
     * @param array{offerKey:string,variantKey:string,optionKeys:string[],projectStage:string,contentReadiness:string,deadline:string} $answers
     *
     * @return array{
     *     offerKey:string, offerLabel:string, contentQuestion:bool, pricingMode:string, disclaimer:string,
     *     variantKey:string, variantLabel:string,
     *     minimumAmount:int, maximumAmount:int,
     *     includes:string[], selectedOptions:array<int, array{key:string,label:string}>,
     *     calculationDetail:array<int, array{label:string,impactMin:int,impactMax:int}>
     * }
     *
     * @throws QuoteEstimateValidationException when a choice is absent from the active catalog
     */
    public function calculate(array $catalog, array $answers): array
    {
        $offer = $this->catalogService->findOffer($catalog, $answers['offerKey']);
        if ($offer === null) {
            throw new QuoteEstimateValidationException(sprintf("Offre inconnue: '%s'.", $answers['offerKey']));
        }

        $variant = $this->catalogService->findVariant($offer, $answers['variantKey']);
        if ($variant === null) {
            throw new QuoteEstimateValidationException(sprintf("Formule inconnue: '%s'.", $answers['variantKey']));
        }

        foreach (['projectStage' => self::STAGES, 'contentReadiness' => self::CONTENT_STATES, 'deadline' => self::DEADLINES] as $field => $allowed) {
            if (!in_array($answers[$field], $allowed, true)) {
                throw new QuoteEstimateValidationException(sprintf("Valeur inconnue pour %s: '%s'.", $field, $answers[$field]));
            }
        }

        $min = (int) $variant['minimumAmount'];
        $max = (int) $variant['maximumAmount'];

        $detail = [[
            'label' => $variant['label'],
            'impactMin' => $min,
            'impactMax' => $max,
        ]];

        $selectedOptions = [];
        foreach ($answers['optionKeys'] as $optionKey) {
            $option = $this->catalogService->findOption($offer, $optionKey);
            if ($option === null) {
                throw new QuoteEstimateValidationException(sprintf("Option inconnue: '%s'.", $optionKey));
            }

            $min += (int) $option['minimumAmount'];
            $max += (int) $option['maximumAmount'];
            $selectedOptions[] = ['key' => $option['key'], 'label' => $option['label']];
            $detail[] = [
                'label' => $option['label'],
                'impactMin' => (int) $option['minimumAmount'],
                'impactMax' => (int) $option['maximumAmount'],
            ];
        }

        $adjustments = $catalog['adjustments'] ?? [];

        // The question only makes sense for offers that ship editorial content:
        // an automation or an assistant is never charged for copywriting.
        $contentQuestionApplies = ($offer['contentQuestion'] ?? true) === true;

        // "Je ne sais pas" never adds a supplement: only an explicit "à rédiger" does.
        if ($contentQuestionApplies && $answers['contentReadiness'] === self::CONTENT_TO_WRITE && isset($adjustments['contentWriting'])) {
            $contentWriting = $adjustments['contentWriting'];
            $min += (int) $contentWriting['minimumAmount'];
            $max += (int) $contentWriting['maximumAmount'];
            $detail[] = [
                'label' => $contentWriting['label'],
                'impactMin' => (int) $contentWriting['minimumAmount'],
                'impactMax' => (int) $contentWriting['maximumAmount'],
            ];
        }

        // A flat supplement, not a multiplier: a multiplier would turn a committed
        // pack back into a range as soon as the client is in a hurry.
        $priorityAmount = (int) ($variant['priorityAmount'] ?? 0);
        if ($answers['deadline'] === self::DEADLINE_PRIORITAIRE && $priorityAmount > 0) {
            $min += $priorityAmount;
            $max += $priorityAmount;
            $detail[] = [
                'label' => self::PRIORITY_LABEL,
                'impactMin' => $priorityAmount,
                'impactMax' => $priorityAmount,
            ];
        }

        $mode = is_string($variant['pricingMode'] ?? null) ? $variant['pricingMode'] : QuotePricingCatalog::MODE_RANGE;

        $min = $this->round($min);
        $max = $this->round($max);
        if ($max < $min) {
            $max = $min + self::ROUNDING_STEP;
        }

        // Rounding must never break the promise of a committed amount.
        if ($mode !== QuotePricingCatalog::MODE_RANGE) {
            $max = $min;
        }

        return [
            'offerKey' => $offer['key'],
            'offerLabel' => $offer['label'],
            'contentQuestion' => $contentQuestionApplies,
            'pricingMode' => $mode,
            'disclaimer' => self::DISCLAIMERS[$mode],
            'variantKey' => $variant['key'],
            'variantLabel' => $variant['label'],
            'minimumAmount' => $min,
            'maximumAmount' => $max,
            'includes' => array_values(array_filter(
                $variant['includes'] ?? [],
                static fn ($item): bool => is_string($item) && trim($item) !== '',
            )),
            'selectedOptions' => $selectedOptions,
            'calculationDetail' => $detail,
        ];
    }

    private function round(int $amount): int
    {
        return (int) round($amount / self::ROUNDING_STEP) * self::ROUNDING_STEP;
    }
}
