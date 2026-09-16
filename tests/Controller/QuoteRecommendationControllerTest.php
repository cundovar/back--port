<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuoteRecommendationController;
use App\Entity\QuotePricingConfiguration;
use App\Repository\QuotePricingConfigurationRepository;
use App\Service\DeepSeekQuoteRecommendationService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuotePricingCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class QuoteRecommendationControllerTest extends TestCase
{
    private const LONG_ENOUGH = 'Je recopie chaque lundi les demandes de trois portails et j’oublie des relances.';

    private const PAYLOAD = [
        'offerKey' => 'automatisation',
        'projectDescription' => self::LONG_ENOUGH,
        'toolKeys' => ['tableur'],
        'projectStage' => 'nouveau',
        'contentReadiness' => 'pret',
        'deadline' => 'normal',
    ];

    public function testProposalsArePricedFromTheCatalogNotFromTheModel(): void
    {
        $service = $this->serviceReturning([
            'essential' => ['variantKey' => 'automatisation-ciblee', 'optionKeys' => ['relances-auto'], 'reasons' => []],
            'complete' => ['variantKey' => 'automatisation-multi-outils', 'optionKeys' => ['relances-auto', 'rapport-hebdo'], 'reasons' => []],
        ]);

        $response = $this->controller($service)->recommend($this->jsonRequest(self::PAYLOAD));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['proposals']);

        // 500 + 200, prix ferme
        self::assertSame(700, $body['proposals'][0]['minimumAmount']);
        self::assertSame(700, $body['proposals'][0]['maximumAmount']);
        self::assertSame('fixed', $body['proposals'][0]['pricingMode']);
        self::assertSame('Une tâche précise à automatiser', $body['proposals'][0]['variantLabel']);

        // 900 + 200 + 150, prix de départ
        self::assertSame(1250, $body['proposals'][1]['minimumAmount']);
        self::assertSame('from', $body['proposals'][1]['pricingMode']);
    }

    public function testIncludesAndOptionLabelsComeFromTheCatalog(): void
    {
        $service = $this->serviceReturning([
            'essential' => ['variantKey' => 'automatisation-ciblee', 'optionKeys' => ['relances-auto'], 'reasons' => []],
        ]);

        $body = json_decode((string) $this->controller($service)->recommend($this->jsonRequest(self::PAYLOAD))->getContent(), true);
        $proposal = $body['proposals'][0];

        self::assertSame(['Relances automatiques par email'], array_column($proposal['selectedOptions'], 'label'));
        self::assertContains('Une automatisation prête à l’emploi', $proposal['includes']);
    }

    public function testTwoIdenticalSelectionsBecomeASingleProposal(): void
    {
        $selection = ['variantKey' => 'automatisation-ciblee', 'optionKeys' => ['relances-auto'], 'reasons' => []];
        $service = $this->serviceReturning(['essential' => $selection, 'complete' => $selection]);

        $body = json_decode((string) $this->controller($service)->recommend($this->jsonRequest(self::PAYLOAD))->getContent(), true);

        self::assertCount(1, $body['proposals']);
        self::assertSame('essential', $body['proposals'][0]['tier']);
    }

    public function testAShortDescriptionNeverReachesTheModel(): void
    {
        $service = $this->createMock(DeepSeekQuoteRecommendationService::class);
        $service->expects(self::never())->method('recommend');

        $payload = ['projectDescription' => 'trop court'] + self::PAYLOAD;
        $response = $this->controller($service)->recommend($this->jsonRequest($payload));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['proposals']);
        self::assertSame(QuoteRecommendationController::REASON_SKIPPED_SHORT, $body['source']);
    }

    public function testAShortDescriptionDoesNotConsumeTheQuota(): void
    {
        $service = $this->createStub(DeepSeekQuoteRecommendationService::class);
        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects(self::never())->method('create');

        $payload = ['projectDescription' => 'court'] + self::PAYLOAD;
        $this->controller($service, $limiter)->recommend($this->jsonRequest($payload));
    }

    public function testAnUnavailableModelStillReturnsAUsableResponse(): void
    {
        $service = $this->createStub(DeepSeekQuoteRecommendationService::class);
        $service->method('recommend')->willReturn(['summary' => '', 'tiers' => [], 'source' => 'unavailable']);

        $response = $this->controller($service)->recommend($this->jsonRequest(self::PAYLOAD));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['proposals']);
        self::assertSame('unavailable', $body['source']);
    }

    public function testQuotaIsRefusedBeforeAnyPaidCall(): void
    {
        $service = $this->createMock(DeepSeekQuoteRecommendationService::class);
        $service->expects(self::never())->method('recommend');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->controller($service, $this->limiterFactory(false))->recommend($this->jsonRequest(self::PAYLOAD));
    }

    public function testAnUnknownOfferIsRejectedWith422(): void
    {
        $service = $this->createMock(DeepSeekQuoteRecommendationService::class);
        $service->expects(self::never())->method('recommend');

        $payload = ['offerKey' => 'inexistante'] + self::PAYLOAD;
        $response = $this->controller($service)->recommend($this->jsonRequest($payload));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAnUnknownEnumValueIsRejectedWith400(): void
    {
        $payload = ['deadline' => 'hier'] + self::PAYLOAD;

        $this->expectException(BadRequestHttpException::class);
        $this->controller($this->createStub(DeepSeekQuoteRecommendationService::class))->recommend($this->jsonRequest($payload));
    }

    public function testAnUnknownToolIsIgnoredInsteadOfBlocking(): void
    {
        $received = null;
        $service = $this->createStub(DeepSeekQuoteRecommendationService::class);
        $service->method('recommend')->willReturnCallback(
            static function (array $offer, array $context) use (&$received): array {
                $received = $context;

                return ['summary' => '', 'tiers' => [], 'source' => 'unavailable'];
            },
        );

        $payload = ['toolKeys' => ['tableur', 'outil-fantome', 'tableur']] + self::PAYLOAD;
        $response = $this->controller($service)->recommend($this->jsonRequest($payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tableur'], $received['toolKeys']);
        self::assertSame(['Excel ou Google Sheets'], $received['toolLabels']);
    }

    private function controller(
        DeepSeekQuoteRecommendationService $service,
        ?RateLimiterFactoryInterface $limiter = null,
    ): QuoteRecommendationController {
        $configuration = new QuotePricingConfiguration();
        $configuration->setCatalog(QuotePricingCatalog::defaultCatalog());

        $repository = $this->createStub(QuotePricingConfigurationRepository::class);
        $repository->method('getActive')->willReturn($configuration);

        return new QuoteRecommendationController(
            new QuoteEstimateCalculator(new QuotePricingCatalog()),
            new QuotePricingCatalog(),
            $service,
            $repository,
            $limiter ?? $this->limiterFactory(true),
        );
    }

    /**
     * @param array<string, array{variantKey:string,optionKeys:string[],reasons:array<string,string>}> $tiers
     */
    private function serviceReturning(array $tiers): DeepSeekQuoteRecommendationService
    {
        $service = $this->createStub(DeepSeekQuoteRecommendationService::class);
        $service->method('recommend')->willReturn([
            'summary' => 'Le prospect veut automatiser ses relances.',
            'tiers' => $tiers,
            'source' => 'deepseek',
        ]);

        return $service;
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

    private function jsonRequest(array $payload): Request
    {
        return Request::create(
            '/api/quote-recommendations',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.7'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
