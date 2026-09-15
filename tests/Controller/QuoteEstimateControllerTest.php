<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuoteEstimateController;
use App\Entity\QuoteEstimate;
use App\Service\DeepSeekQuoteAnalysisService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuoteEstimateNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class QuoteEstimateControllerTest extends TestCase
{
    private const VALID_PAYLOAD = [
        'serviceKey' => 'automation',
        'complexity' => 'standard',
        'integrationsCount' => 2,
        'legacyTakeover' => false,
        'urgency' => false,
        'trainingNeed' => 'light',
        'projectDescription' => 'Automatiser la relance client.',
        'fullName' => 'Jane Doe',
        'email' => 'jane@example.com',
        'consentAccepted' => true,
    ];

    public function testValidSubmissionPersistsAndReturnsEstimate(): void
    {
        $analysis = $this->analysisServiceReturning([
            'summary' => 'Synthèse IA.',
            'recommendedScope' => ['Cadrer les intégrations'],
            'missingQuestions' => ['Quel volume ?'],
            'riskFlags' => [],
            'source' => 'deepseek',
        ]);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (QuoteEstimate $estimate) use (&$persisted): void {
                $persisted = $estimate;
            });
        $em->expects(self::once())->method('flush');

        $notification = $this->createMock(QuoteEstimateNotificationService::class);
        $notification->expects(self::once())->method('notify')->willReturn(true);

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $notification,
            $this->limiterFactory(true),
        );

        $response = $controller->create($this->jsonRequest(self::VALID_PAYLOAD), $em);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('automation', $body['serviceKey']);
        self::assertSame('Synthèse IA.', $body['summary']);
        self::assertSame('deepseek', $body['aiSource']);
        self::assertNotEmpty($body['calculationDetail']);
        self::assertLessThanOrEqual($body['maximumAmount'], $body['minimumAmount']);
        self::assertSame('Estimation indicative, non contractuelle.', $body['disclaimer']);

        self::assertInstanceOf(QuoteEstimate::class, $persisted);
        self::assertSame('Jane Doe', $persisted->getFullName());
        self::assertSame('light', $persisted->getAnswers()['trainingNeed']);
    }

    public function testMissingRequiredFieldIsRejectedBeforeAnyWork(): void
    {
        $payload = self::VALID_PAYLOAD;
        unset($payload['fullName']);

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->create($this->jsonRequest($payload), $em);
    }

    public function testOutOfCatalogServiceKeyReturns422(): void
    {
        $payload = self::VALID_PAYLOAD;
        $payload['serviceKey'] = 'nope';

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $response = $controller->create($this->jsonRequest($payload), $em);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_catalog_value', $body['error']);
    }

    public function testOversizedOptionalContactFieldIsRejectedBeforeAiCall(): void
    {
        $payload = self::VALID_PAYLOAD;
        $payload['company'] = str_repeat('x', 121);

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->create($this->jsonRequest($payload), $em);
    }

    public function testMissingConsentIsRejectedBeforeAiCall(): void
    {
        $payload = self::VALID_PAYLOAD;
        unset($payload['consentAccepted']);

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->create($this->jsonRequest($payload), $em);
    }

    public function testHoneypotIsSilentlyDiscarded(): void
    {
        $payload = self::VALID_PAYLOAD;
        $payload['honeypot'] = 'bot';

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $response = $controller->create($this->jsonRequest($payload), $em);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getContent(), true));
    }

    public function testAiFallbackStillReturnsUsableEstimate(): void
    {
        $analysis = $this->analysisServiceReturning([
            'summary' => 'Synthèse de secours.',
            'recommendedScope' => [],
            'missingQuestions' => [],
            'riskFlags' => [],
            'source' => 'fallback',
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $response = $controller->create($this->jsonRequest(self::VALID_PAYLOAD), $em);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('fallback', $body['aiSource']);
        self::assertSame('Synthèse de secours.', $body['summary']);
        self::assertGreaterThan(0, $body['minimumAmount']);
    }

    public function testRateLimitedRequestReturns429WithoutAiCall(): void
    {
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(false),
        );

        $this->expectException(TooManyRequestsHttpException::class);
        $controller->create($this->jsonRequest(self::VALID_PAYLOAD), $em);
    }

    public function testPersonalDataNeverReachesTheAnalysisService(): void
    {
        $payload = self::VALID_PAYLOAD;
        $payload['company'] = 'ACME';
        $payload['phone'] = '0600000000';

        $capturedContext = null;
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::once())
            ->method('analyze')
            ->willReturnCallback(static function (array $context, array $detail) use (&$capturedContext): array {
                $capturedContext = $context;

                return [
                    'summary' => 'ok',
                    'recommendedScope' => [],
                    'missingQuestions' => [],
                    'riskFlags' => [],
                    'source' => 'deepseek',
                ];
            });

        $em = $this->createStub(EntityManagerInterface::class);

        $controller = new QuoteEstimateController(
            new QuoteEstimateCalculator(),
            $analysis,
            $this->createStub(QuoteEstimateNotificationService::class),
            $this->limiterFactory(true),
        );

        $controller->create($this->jsonRequest($payload), $em);

        self::assertIsArray($capturedContext);
        $flattened = strtolower(json_encode($capturedContext, JSON_THROW_ON_ERROR));
        foreach (['jane doe', 'jane@example.com', 'acme', '0600000000'] as $personalValue) {
            self::assertStringNotContainsString($personalValue, $flattened);
        }
        self::assertArrayNotHasKey('fullName', $capturedContext);
        self::assertArrayNotHasKey('email', $capturedContext);
    }

    private function jsonRequest(array $payload): Request
    {
        return Request::create(
            '/api/quote-estimates',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.1'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function analysisServiceReturning(array $result): DeepSeekQuoteAnalysisService
    {
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::once())->method('analyze')->willReturn($result);

        return $analysis;
    }

    private function limiterFactory(bool $accepted): RateLimiterFactoryInterface
    {
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn($accepted);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }
}
