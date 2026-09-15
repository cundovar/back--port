<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeepSeekQuoteAnalysisService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DeepSeekQuoteAnalysisServiceTest extends TestCase
{
    private const CONTEXT = [
        'serviceLabel' => 'Automatisation',
        'complexityLabel' => 'standard',
        'integrationsCount' => 2,
        'legacyTakeover' => false,
        'urgency' => false,
        'trainingLabel' => 'léger',
        'projectDescription' => 'Relancer les clients automatiquement.',
    ];

    private const DETAIL = [
        ['label' => 'Base Automatisation', 'impactMin' => 800, 'impactMax' => 2500],
    ];

    public function testValidJsonResponseIsMapped(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => 'Projet clair.',
            'recommendedScope' => ['Cadrer les intégrations', ''],
            'missingQuestions' => ['Quel volume ?'],
            'riskFlags' => ['Dépendance à une API tierce'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->analyze(self::CONTEXT, self::DETAIL);

        self::assertSame('deepseek', $result['source']);
        self::assertSame('Projet clair.', $result['summary']);
        self::assertSame(['Cadrer les intégrations'], $result['recommendedScope']);
        self::assertSame(['Quel volume ?'], $result['missingQuestions']);
        self::assertSame(['Dépendance à une API tierce'], $result['riskFlags']);
    }

    public function testTransportFailureFallsBack(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('network down');
        });

        $result = $this->service($client)->analyze(self::CONTEXT, self::DETAIL);

        self::assertSame('fallback', $result['source']);
        self::assertStringContainsString('Automatisation', $result['summary']);
        self::assertNotSame('', $result['summary']);
    }

    public function testMalformedJsonFallsBack(): void
    {
        $result = $this->service($this->clientReturningContent('not json at all'))
            ->analyze(self::CONTEXT, self::DETAIL);

        self::assertSame('fallback', $result['source']);
    }

    public function testMissingSummaryKeyFallsBack(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'recommendedScope' => ['Cadrer'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->analyze(self::CONTEXT, self::DETAIL);

        self::assertSame('fallback', $result['source']);
    }

    public function testEmptyApiKeySkipsHttpCall(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('No HTTP call should be made without an API key.');
        });

        $service = new DeepSeekQuoteAnalysisService($client, new NullLogger(), '', 'gpt-4o-mini', 300, 0.3);
        $result = $service->analyze(self::CONTEXT, self::DETAIL);

        self::assertSame('fallback', $result['source']);
    }

    public function testPromptNeverCarriesPersonalData(): void
    {
        $capturedBody = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = $options['body'] ?? '';

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => json_encode(['summary' => 'ok'])]]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->service($client)->analyze(self::CONTEXT, self::DETAIL);

        self::assertIsString($capturedBody);
        self::assertStringNotContainsString('@example.com', $capturedBody);
        self::assertStringContainsString('Automatisation', $capturedBody);
    }

    private function service(HttpClientInterface $client): DeepSeekQuoteAnalysisService
    {
        return new DeepSeekQuoteAnalysisService($client, new NullLogger(), 'sk-test', 'gpt-4o-mini', 300, 0.3);
    }

    private function clientReturningContent(string $content): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_THROW_ON_ERROR)));
    }
}
