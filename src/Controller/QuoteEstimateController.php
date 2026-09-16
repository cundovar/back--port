<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuoteEstimate;
use App\Exception\QuoteEstimateValidationException;
use App\Repository\QuoteEstimateRepository;
use App\Repository\QuotePricingConfigurationRepository;
use App\Security\AdminTokenGuard;
use App\Service\DeepSeekQuoteAnalysisService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuoteEstimateNotificationService;
use App\Service\QuotePricingCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class QuoteEstimateController
{
    private const MAX_DESCRIPTION_LENGTH = 600;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 255;
    // Mirror the column lengths of portfolio_quote_estimates: a longer value must
    // be refused with a 400, never reach the database and fail there.
    private const MAX_COMPANY_LENGTH = 120;
    private const MAX_PHONE_LENGTH = 40;
    private const MAX_OPTIONS = 12;

    private const ADMIN_STATUSES = [
        QuoteEstimate::STATUS_NEW,
        QuoteEstimate::STATUS_REVIEWED,
        QuoteEstimate::STATUS_QUALIFIED,
        QuoteEstimate::STATUS_ARCHIVED,
    ];

    public function __construct(
        private readonly QuoteEstimateCalculator $calculator,
        private readonly QuotePricingCatalog $catalogService,
        private readonly DeepSeekQuoteAnalysisService $analysisService,
        private readonly QuoteEstimateNotificationService $notificationService,
        private readonly QuotePricingConfigurationRepository $pricingRepository,
        private readonly RateLimiterFactoryInterface $quoteEstimatePublicLimiter,
        private readonly RateLimiterFactoryInterface $quoteEstimatePreviewLimiter,
    ) {
    }

    /**
     * Price preview: no contact details, no persistence, no AI, no email.
     */
    #[Route('/api/quote-estimates/preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->enforceLimit($this->quoteEstimatePreviewLimiter, $request);

        $payload = $this->parseJson($request);
        $answers = $this->validateProjectAnswers($payload);

        $configuration = $this->pricingRepository->getActive();

        try {
            $calculation = $this->calculator->calculate($configuration->getCatalog(), $answers);
        } catch (QuoteEstimateValidationException $exception) {
            return new JsonResponse(['error' => 'invalid_catalog_value', 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse($this->presentCalculation($calculation, $configuration->getVersion()));
    }

    /**
     * Final submission: recomputed server-side, then persisted, qualified and notified.
     */
    #[Route('/api/quote-estimates', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $this->parseJson($request);

        if (isset($payload['honeypot']) && trim((string) $payload['honeypot']) !== '') {
            return new JsonResponse(['ok' => true], 202);
        }

        $this->enforceLimit($this->quoteEstimatePublicLimiter, $request);

        $answers = $this->validateProjectAnswers($payload);
        $contact = $this->validateContact($payload);

        $configuration = $this->pricingRepository->getActive();

        // Never trust a price echoed back by the browser: always recompute.
        try {
            $calculation = $this->calculator->calculate($configuration->getCatalog(), $answers);
        } catch (QuoteEstimateValidationException $exception) {
            return new JsonResponse(['error' => 'invalid_catalog_value', 'message' => $exception->getMessage()], 422);
        }

        $analysis = $this->analysisService->analyze($this->buildProjectContext($answers, $calculation), $calculation['calculationDetail']);

        $estimate = new QuoteEstimate();
        $estimate->setServiceKey($answers['offerKey']);
        $estimate->setOfferKey($answers['offerKey']);
        $estimate->setVariantKey($answers['variantKey']);
        $estimate->setPricingVersion($configuration->getVersion());
        $tools = $this->catalogService->resolveTools($configuration->getCatalog(), $payload['toolKeys'] ?? []);

        // Everything readable later is frozen here, from the catalog used that day:
        // a later price edit must not rewrite what the client was shown.
        $estimate->setAnswers([
            'offerLabel' => $calculation['offerLabel'],
            'variantLabel' => $calculation['variantLabel'],
            'selectedOptions' => $calculation['selectedOptions'],
            'includes' => $calculation['includes'],
            'pricingMode' => $calculation['pricingMode'],
            'disclaimer' => $calculation['disclaimer'],
            'toolKeys' => $tools['keys'],
            'toolLabels' => $tools['labels'],
            'projectStage' => $answers['projectStage'],
            'contentReadiness' => $answers['contentReadiness'],
            'deadline' => $answers['deadline'],
            'projectDescription' => $answers['projectDescription'],
        ]);
        $estimate->setFullName($contact['fullName']);
        $estimate->setEmail($contact['email']);
        $estimate->setCompany($contact['company']);
        $estimate->setPhone($contact['phone']);
        $estimate->setMinimumAmount($calculation['minimumAmount']);
        $estimate->setMaximumAmount($calculation['maximumAmount']);
        $estimate->setCalculationDetail($calculation['calculationDetail']);
        $estimate->setAiSummary($analysis['summary']);
        $estimate->setAiRecommendedScope($analysis['recommendedScope']);
        $estimate->setAiMissingQuestions($analysis['missingQuestions']);
        $estimate->setAiRiskFlags($analysis['riskFlags']);
        $estimate->setAiSource($analysis['source']);

        $em->persist($estimate);
        $em->flush();

        $this->notificationService->notify($estimate);

        return new JsonResponse(
            $this->presentCalculation($calculation, $configuration->getVersion()) + [
                'id' => $estimate->getId(),
                'summary' => $analysis['summary'],
                'recommendedScope' => $analysis['recommendedScope'],
                'missingQuestions' => $analysis['missingQuestions'],
                'riskFlags' => $analysis['riskFlags'],
                'aiSource' => $analysis['source'],
            ],
            201,
        );
    }

    #[Route('/api/admin/quote-estimates', methods: ['GET'])]
    public function listAdmin(Request $request, QuoteEstimateRepository $repository, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $status = $request->query->get('status');
        if (!is_string($status) || !in_array($status, self::ADMIN_STATUSES, true)) {
            $status = null;
        }

        $limit = max(1, min(100, $request->query->getInt('limit', 20)));
        $offset = max(0, $request->query->getInt('offset', 0));

        return new JsonResponse([
            'total' => $repository->countAll($status),
            'items' => array_map(
                fn (QuoteEstimate $estimate) => $this->mapListItem($estimate),
                $repository->findForAdminList($status, $limit, $offset),
            ),
        ]);
    }

    #[Route('/api/admin/quote-estimates/{id}', methods: ['GET'])]
    public function getEstimate(int $id, Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $estimate = $em->getRepository(QuoteEstimate::class)->find($id);
        if (!$estimate) {
            throw new NotFoundHttpException('Quote estimate not found');
        }

        return new JsonResponse($this->mapDetail($estimate));
    }

    #[Route('/api/admin/quote-estimates/{id}', methods: ['PATCH'])]
    public function updateEstimate(int $id, Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $estimate = $em->getRepository(QuoteEstimate::class)->find($id);
        if (!$estimate) {
            throw new NotFoundHttpException('Quote estimate not found');
        }

        $payload = $this->parseJson($request);

        if (isset($payload['status'])) {
            $status = (string) $payload['status'];
            if (!in_array($status, self::ADMIN_STATUSES, true)) {
                throw new BadRequestHttpException('Invalid status');
            }
            if ($status === QuoteEstimate::STATUS_QUALIFIED) {
                $estimate->markAsQualified();
            } else {
                $estimate->setStatus($status);
            }
        }

        if (array_key_exists('notes', $payload)) {
            $estimate->setNotes($payload['notes'] !== null ? (string) $payload['notes'] : null);
        }

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function enforceLimit(RateLimiterFactoryInterface $limiter, Request $request): void
    {
        if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop de simulations depuis cette adresse, réessayez plus tard.');
        }
    }

    private function parseJson(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        return $payload;
    }

    /**
     * @return array{offerKey:string,variantKey:string,optionKeys:string[],projectStage:string,contentReadiness:string,deadline:string,projectDescription:string}
     */
    private function validateProjectAnswers(array $payload): array
    {
        foreach (['offerKey', 'variantKey', 'projectStage', 'contentReadiness', 'deadline'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new BadRequestHttpException(sprintf('Missing required field: %s', $field));
            }
        }

        $optionKeys = $payload['optionKeys'] ?? [];
        if (!is_array($optionKeys) || count($optionKeys) > self::MAX_OPTIONS) {
            throw new BadRequestHttpException('Invalid optionKeys');
        }
        foreach ($optionKeys as $optionKey) {
            if (!is_string($optionKey) || trim($optionKey) === '') {
                throw new BadRequestHttpException('Invalid optionKeys');
            }
        }

        $description = $payload['projectDescription'] ?? '';
        if (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new BadRequestHttpException('Invalid projectDescription');
        }

        return [
            'offerKey' => trim($payload['offerKey']),
            'variantKey' => trim($payload['variantKey']),
            'optionKeys' => array_values(array_unique(array_map('trim', $optionKeys))),
            'projectStage' => trim($payload['projectStage']),
            'contentReadiness' => trim($payload['contentReadiness']),
            'deadline' => trim($payload['deadline']),
            'projectDescription' => trim($description),
        ];
    }

    /**
     * @return array{fullName:string,email:string,company:?string,phone:?string}
     */
    private function validateContact(array $payload): array
    {
        foreach (['fullName', 'email'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new BadRequestHttpException(sprintf('Missing required field: %s', $field));
            }
        }

        if (($payload['consent'] ?? null) !== true) {
            throw new BadRequestHttpException('Consent is required');
        }

        $fullName = trim($payload['fullName']);
        $email = trim($payload['email']);

        if (mb_strlen($fullName) > self::MAX_NAME_LENGTH || mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new BadRequestHttpException('Field too long');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Invalid email');
        }

        return [
            'fullName' => $fullName,
            'email' => $email,
            'company' => $this->optionalString($payload, 'company', self::MAX_COMPANY_LENGTH),
            'phone' => $this->optionalString($payload, 'phone', self::MAX_PHONE_LENGTH),
        ];
    }

    private function optionalString(array $payload, string $field, int $maxLength): ?string
    {
        if (!isset($payload[$field]) || $payload[$field] === null || $payload[$field] === '') {
            return null;
        }

        if (!is_string($payload[$field])) {
            throw new BadRequestHttpException(sprintf('Invalid %s', $field));
        }

        $value = trim($payload[$field]);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new BadRequestHttpException(sprintf('Field too long: %s', $field));
        }

        return $value;
    }

    private function presentCalculation(array $calculation, int $pricingVersion): array
    {
        return [
            'offerKey' => $calculation['offerKey'],
            'offerLabel' => $calculation['offerLabel'],
            'variantKey' => $calculation['variantKey'],
            'variantLabel' => $calculation['variantLabel'],
            'minimumAmount' => $calculation['minimumAmount'],
            'maximumAmount' => $calculation['maximumAmount'],
            'includes' => $calculation['includes'],
            'selectedOptions' => $calculation['selectedOptions'],
            'calculationDetail' => $calculation['calculationDetail'],
            'pricingVersion' => $pricingVersion,
            'pricingMode' => $calculation['pricingMode'],
            // The wording follows the commercial mode of the variant, never a constant.
            'disclaimer' => $calculation['disclaimer'],
        ];
    }

    /**
     * Only project-shaped data: contact details never reach the model.
     */
    private function buildProjectContext(array $answers, array $calculation): array
    {
        return [
            'offerLabel' => $calculation['offerLabel'],
            'variantLabel' => $calculation['variantLabel'],
            'optionLabels' => array_map(static fn (array $option): string => $option['label'], $calculation['selectedOptions']),
            'projectStage' => $answers['projectStage'],
            'contentReadiness' => $answers['contentReadiness'],
            'deadline' => $answers['deadline'],
            'projectDescription' => $answers['projectDescription'],
        ];
    }

    private function mapListItem(QuoteEstimate $estimate): array
    {
        return [
            'id' => $estimate->getId(),
            'offerKey' => $estimate->getOfferKey() ?? $estimate->getServiceKey(),
            'variantKey' => $estimate->getVariantKey(),
            'fullName' => $estimate->getFullName(),
            'email' => $estimate->getEmail(),
            'minimumAmount' => $estimate->getMinimumAmount(),
            'maximumAmount' => $estimate->getMaximumAmount(),
            'status' => $estimate->getStatus(),
            'pricingVersion' => $estimate->getPricingVersion(),
            'createdAt' => $estimate->getCreatedAt()->format('c'),
            'qualifiedAt' => $estimate->getQualifiedAt()?->format('c'),
        ];
    }

    private function mapDetail(QuoteEstimate $estimate): array
    {
        return $this->mapListItem($estimate) + [
            'company' => $estimate->getCompany(),
            'phone' => $estimate->getPhone(),
            'answers' => $estimate->getAnswers(),
            'calculationDetail' => $estimate->getCalculationDetail(),
            'summary' => $estimate->getAiSummary(),
            'recommendedScope' => $estimate->getAiRecommendedScope() ?? [],
            'missingQuestions' => $estimate->getAiMissingQuestions() ?? [],
            'riskFlags' => $estimate->getAiRiskFlags() ?? [],
            'aiSource' => $estimate->getAiSource(),
            'notes' => $estimate->getNotes(),
        ];
    }
}
