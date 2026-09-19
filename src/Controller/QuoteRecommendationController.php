<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\QuoteEstimateValidationException;
use App\Repository\QuotePricingConfigurationRepository;
use App\Service\DeepSeekQuoteRecommendationService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuotePricingCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous, optional and free for the visitor: no contact details, no database
 * write, no email. Every amount and every label returned here comes from the
 * active catalog, never from the model.
 */
final class QuoteRecommendationController
{
    /** Below this, the description says nothing the structured answers do not already say. */
    private const MIN_DESCRIPTION_LENGTH = 30;
    private const MAX_DESCRIPTION_LENGTH = 600;
    private const MAX_TOOLS = 12;

    private const TIER_LABELS = [
        'essential' => 'Solution essentielle',
        'complete' => 'Solution complète',
    ];

    public const REASON_SKIPPED_SHORT = 'description_too_short';

    public function __construct(
        private readonly QuoteEstimateCalculator $calculator,
        private readonly QuotePricingCatalog $catalogService,
        private readonly DeepSeekQuoteRecommendationService $recommendationService,
        private readonly QuotePricingConfigurationRepository $pricingRepository,
        private readonly RateLimiterFactoryInterface $quoteRecommendationLimiter,
    ) {
    }

    #[Route('/api/quote-recommendations', methods: ['POST'])]
    public function recommend(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $configuration = $this->pricingRepository->getActive();
        $catalog = $configuration->getCatalog();

        $offerKey = $payload['offerKey'] ?? null;
        if (!is_string($offerKey) || $offerKey === '') {
            throw new BadRequestHttpException('Missing field: offerKey');
        }

        $offer = $this->catalogService->findOffer($catalog, $offerKey);
        if ($offer === null) {
            return new JsonResponse(['error' => 'invalid_catalog_value', 'message' => 'Offre inconnue.'], 422);
        }

        $context = $this->buildContext($payload, $catalog);

        // Too short to say anything useful: no paid call, the manual choice stands.
        if (mb_strlen($context['projectDescription']) < self::MIN_DESCRIPTION_LENGTH) {
            return new JsonResponse($this->emptyResponse($configuration->getVersion(), self::REASON_SKIPPED_SHORT));
        }

        $this->enforceLimit($request);

        $recommendation = $this->recommendationService->recommend($offer, $context);
        if ($recommendation['tiers'] === []) {
            return new JsonResponse($this->emptyResponse($configuration->getVersion(), $recommendation['source']));
        }

        return new JsonResponse([
            'summary' => $recommendation['summary'],
            'proposals' => $this->priceTiers($recommendation['tiers'], $catalog, $context, $configuration->getVersion()),
            'source' => $recommendation['source'],
            'pricingVersion' => $configuration->getVersion(),
        ]);
    }

    /**
     * Prices every tier with the same calculator as the rest of the journey, then
     * drops a tier that ends up identical to the one before it.
     *
     * @param array<string, array{variantKey:string,optionKeys:string[],reasons:array<string,string>}> $tiers
     *
     * @return list<array<string, mixed>>
     */
    private function priceTiers(array $tiers, array $catalog, array $context, int $pricingVersion): array
    {
        $proposals = [];
        $seen = [];

        foreach ($tiers as $tier => $selection) {
            $answers = [
                'offerKey' => $context['offerKey'],
                'variantKey' => $selection['variantKey'],
                'optionKeys' => $selection['optionKeys'],
                'projectStage' => $context['projectStage'],
                'contentReadiness' => $context['contentReadiness'],
                'deadline' => $context['deadline'],
            ];

            try {
                $calculation = $this->calculator->calculate($catalog, $answers);
            } catch (QuoteEstimateValidationException) {
                continue;
            }

            $signature = $selection['variantKey'] . '|' . implode(',', $selection['optionKeys']);
            if (in_array($signature, $seen, true)) {
                continue;
            }
            $seen[] = $signature;

            $proposals[] = [
                'tier' => $tier,
                'title' => self::TIER_LABELS[$tier] ?? $tier,
                'variantKey' => $calculation['variantKey'],
                'variantLabel' => $calculation['variantLabel'],
                'includes' => $calculation['includes'],
                'selectedOptions' => $calculation['selectedOptions'],
                'optionKeys' => $selection['optionKeys'],
                'minimumAmount' => $calculation['minimumAmount'],
                'maximumAmount' => $calculation['maximumAmount'],
                'pricingMode' => $calculation['pricingMode'],
                'disclaimer' => $calculation['disclaimer'],
                'calculationDetail' => $calculation['calculationDetail'],
                'reasons' => $selection['reasons'],
                'pricingVersion' => $pricingVersion,
            ];
        }

        return $proposals;
    }

    /**
     * @return array{offerKey:string,projectDescription:string,toolKeys:string[],toolLabels:string[],projectStage:string,contentReadiness:string,deadline:string}
     */
    private function buildContext(array $payload, array $catalog): array
    {
        $description = $payload['projectDescription'] ?? '';
        if (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new BadRequestHttpException('Invalid field: projectDescription');
        }

        $enums = [
            'projectStage' => [QuoteEstimateCalculator::STAGE_NEW, QuoteEstimateCalculator::STAGE_EXISTING],
            'contentReadiness' => [
                QuoteEstimateCalculator::CONTENT_READY,
                QuoteEstimateCalculator::CONTENT_TO_WRITE,
                QuoteEstimateCalculator::CONTENT_UNKNOWN,
            ],
            'deadline' => [
                QuoteEstimateCalculator::DEADLINE_FLEXIBLE,
                QuoteEstimateCalculator::DEADLINE_NORMAL,
                QuoteEstimateCalculator::DEADLINE_PRIORITAIRE,
            ],
        ];

        $answers = [];
        foreach ($enums as $field => $allowed) {
            $value = $payload[$field] ?? $allowed[0];
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new BadRequestHttpException(sprintf('Invalid field: %s', $field));
            }
            $answers[$field] = $value;
        }

        if (isset($payload['toolKeys']) && !is_array($payload['toolKeys'])) {
            throw new BadRequestHttpException('Invalid field: toolKeys');
        }

        $tools = $this->catalogService->resolveTools($catalog, $payload['toolKeys'] ?? [], self::MAX_TOOLS);

        return [
            'offerKey' => $payload['offerKey'],
            'projectDescription' => trim($description),
            'toolKeys' => $tools['keys'],
            'toolLabels' => $tools['labels'],
        ] + $answers;
    }

    /**
     * @return array{summary:string,proposals:list<mixed>,source:string,pricingVersion:int}
     */
    private function emptyResponse(int $pricingVersion, string $source): array
    {
        return ['summary' => '', 'proposals' => [], 'source' => $source, 'pricingVersion' => $pricingVersion];
    }

    private function enforceLimit(Request $request): void
    {
        if (!$this->quoteRecommendationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop d’analyses depuis cette adresse, réessayez plus tard.');
        }
    }
}
