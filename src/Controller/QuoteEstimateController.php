<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuoteEstimate;
use App\Exception\QuoteEstimateValidationException;
use App\Repository\QuoteEstimateRepository;
use App\Security\AdminTokenGuard;
use App\Service\DeepSeekQuoteAnalysisService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuoteEstimateNotificationService;
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
    private const MAX_INTEGRATIONS_INPUT = 20;

    private const DISCLAIMER = 'Estimation indicative, non contractuelle.';

    private const ADMIN_STATUSES = [
        QuoteEstimate::STATUS_NEW,
        QuoteEstimate::STATUS_REVIEWED,
        QuoteEstimate::STATUS_QUALIFIED,
        QuoteEstimate::STATUS_ARCHIVED,
    ];

    private const COMPLEXITY_LABELS = [
        'simple' => 'simple',
        'standard' => 'standard',
        'complexe' => 'complexe',
    ];

    private const SERVICE_LABELS = [
        'automation' => 'Automatisation',
        'ai-assistant' => 'Assistant IA',
        'refonte' => 'Refonte',
        'custom-tool' => 'Outil sur mesure',
        'wordpress' => 'WordPress',
    ];

    private const TRAINING_LABELS = [
        'none' => 'aucun',
        'light' => 'léger',
        'full' => 'complet',
    ];

    public function __construct(
        private readonly QuoteEstimateCalculator $calculator,
        private readonly DeepSeekQuoteAnalysisService $analysisService,
        private readonly QuoteEstimateNotificationService $notificationService,
        private readonly RateLimiterFactoryInterface $quoteEstimatePublicLimiter,
    ) {
    }

    #[Route('/api/quote-estimates', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $this->parseJson($request);

        if (isset($payload['honeypot']) && trim((string) $payload['honeypot']) !== '') {
            return new JsonResponse(['ok' => true], 202);
        }

        if (!$this->quoteEstimatePublicLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(
                null,
                'Trop de simulations depuis cette adresse, réessayez plus tard.',
            );
        }

        $input = $this->validatePayload($payload);

        try {
            $calculation = $this->calculator->calculate(
                $input['serviceKey'],
                $input['complexity'],
                $input['integrationsCount'],
                $input['legacyTakeover'],
                $input['urgency'],
                $input['trainingNeed'],
            );
        } catch (QuoteEstimateValidationException $exception) {
            return new JsonResponse([
                'error' => 'invalid_catalog_value',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $analysis = $this->analysisService->analyze(
            $this->buildProjectContext($input),
            $calculation['calculationDetail'],
        );

        $estimate = new QuoteEstimate();
        $estimate->setServiceKey($input['serviceKey']);
        $estimate->setAnswers([
            'complexity' => $input['complexity'],
            'integrationsCount' => $input['integrationsCount'],
            'legacyTakeover' => $input['legacyTakeover'],
            'urgency' => $input['urgency'],
            'trainingNeed' => $input['trainingNeed'],
            'projectDescription' => $input['projectDescription'],
        ]);
        $estimate->setFullName($input['fullName']);
        $estimate->setEmail($input['email']);
        $estimate->setCompany($input['company']);
        $estimate->setPhone($input['phone']);
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

        return new JsonResponse([
            'id' => $estimate->getId(),
            'serviceKey' => $estimate->getServiceKey(),
            'minimumAmount' => $estimate->getMinimumAmount(),
            'maximumAmount' => $estimate->getMaximumAmount(),
            'calculationDetail' => $estimate->getCalculationDetail(),
            'summary' => $analysis['summary'],
            'recommendedScope' => $analysis['recommendedScope'],
            'missingQuestions' => $analysis['missingQuestions'],
            'riskFlags' => $analysis['riskFlags'],
            'aiSource' => $analysis['source'],
            'disclaimer' => self::DISCLAIMER,
        ], 201);
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

        $estimates = $repository->findForAdminList($status, $limit, $offset);

        return new JsonResponse([
            'total' => $repository->countAll($status),
            'items' => array_map(fn (QuoteEstimate $estimate) => $this->mapListItem($estimate), $estimates),
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

    private function mapListItem(QuoteEstimate $estimate): array
    {
        return [
            'id' => $estimate->getId(),
            'serviceKey' => $estimate->getServiceKey(),
            'fullName' => $estimate->getFullName(),
            'email' => $estimate->getEmail(),
            'minimumAmount' => $estimate->getMinimumAmount(),
            'maximumAmount' => $estimate->getMaximumAmount(),
            'status' => $estimate->getStatus(),
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

    private function parseJson(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        return $payload;
    }

    /**
     * @return array{serviceKey:string,complexity:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingNeed:string,projectDescription:string,fullName:string,email:string,company:?string,phone:?string}
     */
    private function validatePayload(array $payload): array
    {
        foreach (['serviceKey', 'complexity', 'fullName', 'email'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new BadRequestHttpException(sprintf('Missing required field: %s', $field));
            }
        }

        $fullName = trim((string) $payload['fullName']);
        $email = trim((string) $payload['email']);

        if (mb_strlen($fullName) > self::MAX_NAME_LENGTH || mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new BadRequestHttpException('Field too long');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Invalid email');
        }

        $integrationsCount = $payload['integrationsCount'] ?? 0;
        if (!is_int($integrationsCount) || $integrationsCount < 0 || $integrationsCount > self::MAX_INTEGRATIONS_INPUT) {
            throw new BadRequestHttpException('Invalid integrationsCount');
        }

        foreach (['legacyTakeover', 'urgency'] as $flag) {
            if (isset($payload[$flag]) && !is_bool($payload[$flag])) {
                throw new BadRequestHttpException(sprintf('Invalid %s', $flag));
            }
        }

        $projectDescription = $payload['projectDescription'] ?? '';
        if (!is_string($projectDescription) || mb_strlen($projectDescription) > self::MAX_DESCRIPTION_LENGTH) {
            throw new BadRequestHttpException('Invalid projectDescription');
        }

        $trainingNeed = $payload['trainingNeed'] ?? 'none';
        if (!is_string($trainingNeed) || $trainingNeed === '') {
            throw new BadRequestHttpException('Invalid trainingNeed');
        }

        return [
            'serviceKey' => trim((string) $payload['serviceKey']),
            'complexity' => trim((string) $payload['complexity']),
            'integrationsCount' => $integrationsCount,
            'legacyTakeover' => (bool) ($payload['legacyTakeover'] ?? false),
            'urgency' => (bool) ($payload['urgency'] ?? false),
            'trainingNeed' => $trainingNeed,
            'projectDescription' => trim($projectDescription),
            'fullName' => $fullName,
            'email' => $email,
            'company' => isset($payload['company']) && is_string($payload['company']) && trim($payload['company']) !== ''
                ? trim($payload['company'])
                : null,
            'phone' => isset($payload['phone']) && is_string($payload['phone']) && trim($payload['phone']) !== ''
                ? trim($payload['phone'])
                : null,
        ];
    }

    /**
     * Personal contact details are deliberately excluded: the AI only ever sees project answers.
     *
     * @param array{serviceKey:string,complexity:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingNeed:string,projectDescription:string,fullName:string,email:string,company:?string,phone:?string} $input
     *
     * @return array{serviceLabel:string,complexityLabel:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingLabel:string,projectDescription:string}
     */
    private function buildProjectContext(array $input): array
    {
        return [
            'serviceLabel' => self::SERVICE_LABELS[$input['serviceKey']] ?? $input['serviceKey'],
            'complexityLabel' => self::COMPLEXITY_LABELS[$input['complexity']] ?? $input['complexity'],
            'integrationsCount' => $input['integrationsCount'],
            'legacyTakeover' => $input['legacyTakeover'],
            'urgency' => $input['urgency'],
            'trainingLabel' => self::TRAINING_LABELS[$input['trainingNeed']] ?? $input['trainingNeed'],
            'projectDescription' => $input['projectDescription'],
        ];
    }
}
