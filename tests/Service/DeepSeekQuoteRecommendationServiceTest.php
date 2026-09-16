<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeepSeekQuoteRecommendationService;
use App\Service\QuotePricingCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DeepSeekQuoteRecommendationServiceTest extends TestCase
{
    private const CONTEXT = [
        'projectDescription' => 'Je recopie les demandes de trois portails et j’oublie des relances.',
        'toolLabels' => ['Excel ou Google Sheets'],
        'projectStage' => 'nouveau',
        'contentReadiness' => 'pret',
        'deadline' => 'normal',
    ];

    private function offer(): array
    {
        foreach (QuotePricingCatalog::defaultCatalog()['offers'] as $offer) {
            if ($offer['key'] === 'automatisation') {
                return $offer;
            }
        }

        self::fail('Offre de test absente du catalogue.');
    }

    public function testValidSelectionIsKept(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => "Vous voulez  arreter \n de recopier vos relances.",
            'essential' => [
                'variantKey' => 'automatisation-ciblee',
                'optionKeys' => ['relances-auto'],
                'reasons' => ['relances-auto' => 'Vous parlez de relances oubliées.'],
            ],
            'complete' => [
                'variantKey' => 'automatisation-multi-outils',
                'optionKeys' => ['relances-auto', 'rapport-hebdo'],
                'reasons' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertSame('deepseek', $result['source']);
        self::assertSame('Vous voulez arreter de recopier vos relances.', $result['summary']);
        self::assertSame('automatisation-ciblee', $result['tiers']['essential']['variantKey']);
        self::assertSame(['relances-auto'], $result['tiers']['essential']['optionKeys']);
        self::assertSame(['relances-auto', 'rapport-hebdo'], $result['tiers']['complete']['optionKeys']);
    }

    public function testAnInventedOptionIsDropped(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => 'Besoin clair.',
            'essential' => [
                'variantKey' => 'automatisation-ciblee',
                'optionKeys' => ['relances-auto', 'alertes-en-cas-d-echec', 'historique-des-actions'],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertSame(['relances-auto'], $result['tiers']['essential']['optionKeys']);
    }

    public function testATierWithAnInventedVariantIsDropped(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => 'Besoin clair.',
            'essential' => ['variantKey' => 'automatisation-ciblee', 'optionKeys' => []],
            'complete' => ['variantKey' => 'formule-premium-inventee', 'optionKeys' => ['relances-auto']],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertArrayHasKey('essential', $result['tiers']);
        self::assertArrayNotHasKey('complete', $result['tiers']);
    }

    public function testAJustificationSurvivesOnlyOnAKeptKey(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => 'Besoin clair.',
            'essential' => [
                'variantKey' => 'automatisation-ciblee',
                'optionKeys' => ['relances-auto'],
                'reasons' => [
                    'relances-auto' => 'Vous parlez de relances oubliées.',
                    'alertes-inventees' => 'Livrable que le catalogue ne connaît pas.',
                    'rapport-hebdo' => 'Option valide mais non retenue dans ce périmètre.',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertSame(['relances-auto'], array_keys($result['tiers']['essential']['reasons']));
    }

    public function testNoUsableKeyFallsBackToUnavailable(): void
    {
        $client = $this->clientReturningContent(json_encode([
            'summary' => 'Besoin clair.',
            'essential' => ['variantKey' => 'inexistant', 'optionKeys' => []],
        ], JSON_THROW_ON_ERROR));

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertSame('unavailable', $result['source']);
        self::assertSame([], $result['tiers']);
    }

    public function testInvalidJsonFallsBackToUnavailable(): void
    {
        $result = $this->service($this->clientReturningContent('pas du json'))->recommend($this->offer(), self::CONTEXT);

        self::assertSame('unavailable', $result['source']);
    }

    public function testTransportFailureNeverThrows(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'timeout']);
        });

        $result = $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertSame('unavailable', $result['source']);
        self::assertSame([], $result['tiers']);
    }

    public function testAMissingApiKeyMakesNoCallAtAll(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}');
        });

        $service = new DeepSeekQuoteRecommendationService($client, new NullLogger(), '', 'deepseek-chat', 800, 0.3, 5);
        $result = $service->recommend($this->offer(), self::CONTEXT);

        self::assertSame(0, $calls);
        self::assertSame('unavailable', $result['source']);
    }

    public function testNoAmountAndNoContactDetailAreSentToTheModel(): void
    {
        $sent = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['body'] ?? '';

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"summary":"ok","essential":{"variantKey":"automatisation-ciblee","optionKeys":[]}}']]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertIsString($sent);

        // No amount: the model must never be able to anchor on, or echo back, a price.
        foreach (['minimumAmount', 'maximumAmount', 'priorityAmount', '500', '900', '200', '150'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sent, sprintf('"%s" ne doit pas être envoyé au modèle.', $forbidden));
        }

        // No contact detail: the payload carries an email address nowhere.
        self::assertStringNotContainsString('@', $sent);
        self::assertDoesNotMatchRegularExpression('/\bfullName\b|\bphone\b|\bcompany\b/', $sent);
    }

    /**
     * The summary is read by the prospect on screen. Without this instruction the
     * model drifted into "le prospect perd une demi-journee", talking about the
     * reader instead of to them.
     */
    public function testTheModelIsToldToAddressTheProspectDirectly(): void
    {
        $sent = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['body'] ?? '';

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"summary":"ok","essential":{"variantKey":"automatisation-ciblee","optionKeys":[]}}']]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->service($client)->recommend($this->offer(), self::CONTEXT);

        self::assertIsString($sent);
        self::assertStringContainsString('vouvoie-le', $sent);
        self::assertStringContainsString('Aucune troisieme personne', $sent);
    }

    private function service(HttpClientInterface $client): DeepSeekQuoteRecommendationService
    {
        return new DeepSeekQuoteRecommendationService($client, new NullLogger(), 'test-key', 'deepseek-chat', 800, 0.3, 5);
    }

    private function clientReturningContent(string $content): MockHttpClient
    {
        return new MockHttpClient(static function () use ($content): MockResponse {
            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => $content]]],
            ], JSON_THROW_ON_ERROR));
        });
    }
}
