<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuoteEstimateController;
use App\Entity\QuoteEstimate;
use App\Entity\QuotePricingConfiguration;
use App\Repository\QuotePricingConfigurationRepository;
use App\Service\DeepSeekQuoteAnalysisService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuoteEstimateNotificationService;
use App\Service\QuotePricingCatalog;
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
    private const PROJECT_ANSWERS = [
        'offerKey' => 'site-vitrine',
        'variantKey' => 'wordpress-vitrine',
        'optionKeys' => ['prise-rdv'],
        'projectStage' => 'nouveau',
        'contentReadiness' => 'pret',
        'deadline' => 'normal',
        'projectDescription' => 'Un site pour mon cabinet.',
    ];

    private const CONTACT = [
        'fullName' => 'Claire Martin',
        'email' => 'claire@example.com',
        'consent' => true,
    ];

    public function testPreviewReturnsPriceWithoutContactPersistenceOrAi(): void
    {
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $notification = $this->createMock(QuoteEstimateNotificationService::class);
        $notification->expects(self::never())->method('notify');
        $notification->expects(self::never())->method('notifyClient');

        $response = $this->controller($analysis, $notification)->preview($this->jsonRequest(self::PROJECT_ANSWERS));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);

        // 900 (pack ferme) + 250 (prise de rendez-vous)
        self::assertSame(1150, $body['minimumAmount']);
        self::assertSame(1150, $body['maximumAmount']);
        self::assertSame('fixed', $body['pricingMode']);
        self::assertSame('Prix ferme pour le périmètre décrit ci-dessus.', $body['disclaimer']);
        self::assertNotEmpty($body['includes']);
        self::assertSame(['Prise de rendez-vous en ligne'], array_column($body['selectedOptions'], 'label'));
        self::assertSame(7, $body['pricingVersion']);
        self::assertArrayNotHasKey('id', $body);
    }

    public function testPreviewOfARangeOfferExposesBothBoundsAndTheIndicativeWording(): void
    {
        $answers = ['offerKey' => 'refonte', 'variantKey' => 'refonte-ciblee', 'optionKeys' => []] + self::PROJECT_ANSWERS;

        $response = $this->controller()->preview($this->jsonRequest($answers));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame('range', $body['pricingMode']);
        self::assertSame(400, $body['minimumAmount']);
        self::assertSame(900, $body['maximumAmount']);
        self::assertStringContainsString('indicative', $body['disclaimer']);
    }

    public function testPreviewRejectsAnUnknownVariantWith422(): void
    {
        $payload = self::PROJECT_ANSWERS;
        $payload['variantKey'] = 'inexistant';

        $response = $this->controller()->preview($this->jsonRequest($payload));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_catalog_value', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testPreviewRejectsMissingAnswerWith400(): void
    {
        $payload = self::PROJECT_ANSWERS;
        unset($payload['deadline']);

        $response = $this->controller()->preview($this->jsonRequest($payload));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_field', $body['error']);
        self::assertSame('deadline', $body['field']);
    }

    public function testFinalSubmissionRecalculatesAndIgnoresClientAmounts(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['minimumAmount'] = 9;
        $payload['maximumAmount'] = 9;

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (QuoteEstimate $estimate) use (&$persisted): void {
                $persisted = $estimate;
            },
        );
        $em->expects(self::once())->method('flush');

        $response = $this->controller()->create($this->jsonRequest($payload), $em);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(1150, $body['minimumAmount']);
        self::assertSame(1150, $body['maximumAmount']);
        self::assertInstanceOf(QuoteEstimate::class, $persisted);
        self::assertSame(1150, $persisted->getMinimumAmount());
        self::assertSame(7, $persisted->getPricingVersion());
        self::assertSame('site-vitrine', $persisted->getOfferKey());
        self::assertSame('wordpress-vitrine', $persisted->getVariantKey());
    }

    public function testTheFrozenAnswersCarryToolsModeAndCatalogContent(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['toolKeys'] = ['tableur', 'outil-fantome'];

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (QuoteEstimate $estimate) use (&$persisted): void {
                $persisted = $estimate;
            },
        );
        $em->expects(self::once())->method('flush');

        $this->controller()->create($this->jsonRequest($payload), $em);

        $answers = $persisted->getAnswers();
        self::assertSame(['tableur'], $answers['toolKeys']);
        self::assertSame(['Excel ou Google Sheets'], $answers['toolLabels']);
        self::assertSame('fixed', $answers['pricingMode']);
        self::assertSame('Prix ferme pour le périmètre décrit ci-dessus.', $answers['disclaimer']);
        // Deliverables are frozen from the catalog, never from any AI text.
        self::assertContains('Interface WordPress pour modifier vos textes vous-même', $answers['includes']);
        self::assertSame(['Prise de rendez-vous en ligne'], array_column($answers['selectedOptions'], 'label'));
    }

    public function testFinalSubmissionRequiresConsent(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['consent'] = false;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $response = $this->controller()->create($this->jsonRequest($payload), $em);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('consent', json_decode((string) $response->getContent(), true)['field']);
    }

    public function testOversizedCompanyOrPhoneIsRefusedBeforeReachingTheDatabase(): void
    {
        foreach (['company' => 121, 'phone' => 41] as $field => $length) {
            $payload = self::PROJECT_ANSWERS + self::CONTACT;
            $payload[$field] = str_repeat('a', $length);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('persist');

            $response = $this->controller()->create($this->jsonRequest($payload), $em);

            self::assertSame(400, $response->getStatusCode(), sprintf('A too long %s should be refused.', $field));
            self::assertSame($field, json_decode((string) $response->getContent(), true)['field']);
        }
    }

    public function testCompanySentWithTheWrongTypeIsRefused(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['company'] = ['unexpected'];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $response = $this->controller()->create($this->jsonRequest($payload), $em);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('company', json_decode((string) $response->getContent(), true)['field']);
    }

    /**
     * The failure a client actually hit: the browser accepted the address, the
     * server refused it, and the form could only say "l’envoi a échoué".
     */
    public function testRefusedEmailNamesTheFieldInsteadOfFailingBlind(): void
    {
        foreach (['andré@gmail.com', 'jean..dupont@gmail.com', '.jean@gmail.com', 'jean@gmail'] as $email) {
            $payload = self::PROJECT_ANSWERS + self::CONTACT;
            $payload['email'] = $email;

            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('persist');

            $response = $this->controller()->create($this->jsonRequest($payload), $em);
            $body = json_decode((string) $response->getContent(), true);

            self::assertSame(400, $response->getStatusCode(), $email);
            self::assertSame('invalid_field', $body['error']);
            self::assertSame('email', $body['field'], $email);
        }
    }

    public function testCompanyAndPhoneAtTheMaximumLengthAreAccepted(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['company'] = str_repeat('a', 120);
        $payload['phone'] = str_repeat('1', 40);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (QuoteEstimate $estimate) use (&$persisted): void {
                $persisted = $estimate;
            },
        );
        $em->expects(self::once())->method('flush');

        $this->controller()->create($this->jsonRequest($payload), $em);

        self::assertSame(120, mb_strlen((string) $persisted->getCompany()));
        self::assertSame(40, mb_strlen((string) $persisted->getPhone()));
    }

    public function testHoneypotIsDiscardedBeforeAnyWork(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['honeypot'] = 'bot';

        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $response = $this->controller($analysis)->create($this->jsonRequest($payload), $em);

        self::assertSame(202, $response->getStatusCode());
    }

    public function testAiFailureStillReturnsAndStoresThePrice(): void
    {
        $analysis = $this->analysisReturning('fallback');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        $response = $this->controller($analysis)->create($this->jsonRequest(self::PROJECT_ANSWERS + self::CONTACT), $em);

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('fallback', $body['aiSource']);
        self::assertSame(1150, $body['minimumAmount']);
    }

    public function testRateLimitedSubmissionSkipsAiAndPersistence(): void
    {
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::never())->method('analyze');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $controller = $this->controller($analysis, null, false);

        $this->expectException(TooManyRequestsHttpException::class);
        $controller->create($this->jsonRequest(self::PROJECT_ANSWERS + self::CONTACT), $em);
    }

    public function testContactDetailsNeverReachTheModel(): void
    {
        $payload = self::PROJECT_ANSWERS + self::CONTACT;
        $payload['company'] = 'Cabinet Martin';
        $payload['phone'] = '0600000000';

        $captured = null;
        $analysis = $this->createMock(DeepSeekQuoteAnalysisService::class);
        $analysis->expects(self::once())->method('analyze')->willReturnCallback(
            static function (array $context) use (&$captured): array {
                $captured = $context;

                return ['summary' => 'ok', 'recommendedScope' => [], 'missingQuestions' => [], 'riskFlags' => [], 'source' => 'deepseek'];
            },
        );

        $this->controller($analysis)->create($this->jsonRequest($payload), $this->createStub(EntityManagerInterface::class));

        $flattened = strtolower(json_encode($captured, JSON_THROW_ON_ERROR));
        foreach (['claire', 'example.com', 'cabinet martin', '0600000000'] as $personal) {
            self::assertStringNotContainsString($personal, $flattened);
        }
    }

    public function testSubmissionAcknowledgesTheProspectAsWellAsTheOwner(): void
    {
        $notification = $this->createMock(QuoteEstimateNotificationService::class);
        $notification->expects(self::once())->method('notify');
        $notification->expects(self::once())->method('notifyClient');

        $response = $this->controller(null, $notification)->create(
            $this->jsonRequest(self::PROJECT_ANSWERS + self::CONTACT),
            $this->persistingEntityManager(),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * A mail outage must never invalidate an estimate that is already saved.
     */
    public function testSubmissionSucceedsWhenTheClientAcknowledgementCannotBeSent(): void
    {
        $notification = $this->createStub(QuoteEstimateNotificationService::class);
        $notification->method('notify')->willReturn(false);
        $notification->method('notifyClient')->willReturn(false);

        $response = $this->controller(null, $notification)->create(
            $this->jsonRequest(self::PROJECT_ANSWERS + self::CONTACT),
            $this->persistingEntityManager(),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    private function persistingEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        return $em;
    }

    private function controller(
        ?DeepSeekQuoteAnalysisService $analysis = null,
        ?QuoteEstimateNotificationService $notification = null,
        bool $limitAccepted = true,
    ): QuoteEstimateController {
        return new QuoteEstimateController(
            new QuoteEstimateCalculator(new QuotePricingCatalog()),
            new QuotePricingCatalog(),
            $analysis ?? $this->analysisReturning('deepseek'),
            $notification ?? $this->createStub(QuoteEstimateNotificationService::class),
            $this->pricingRepository(),
            $this->limiterFactory($limitAccepted),
            $this->limiterFactory($limitAccepted),
        );
    }

    private function pricingRepository(): QuotePricingConfigurationRepository
    {
        $configuration = new QuotePricingConfiguration();
        $configuration->setCatalog(QuotePricingCatalog::defaultCatalog());
        for ($i = 1; $i < 7; ++$i) {
            $configuration->bumpVersion();
        }

        $repository = $this->createStub(QuotePricingConfigurationRepository::class);
        $repository->method('getActive')->willReturn($configuration);

        return $repository;
    }

    private function analysisReturning(string $source): DeepSeekQuoteAnalysisService
    {
        $analysis = $this->createStub(DeepSeekQuoteAnalysisService::class);
        $analysis->method('analyze')->willReturn([
            'summary' => 'Synthèse.',
            'recommendedScope' => [],
            'missingQuestions' => [],
            'riskFlags' => [],
            'source' => $source,
        ]);

        return $analysis;
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
