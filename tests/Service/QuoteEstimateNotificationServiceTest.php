<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuoteEstimate;
use App\Service\BrandedEmailRenderer;
use App\Service\BrevoEmailSender;
use App\Service\QuoteEstimateNotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QuoteEstimateNotificationServiceTest extends TestCase
{
    private const OWNER_EMAIL = 'contact@exemple.fr';
    private const SENDER_EMAIL = 'no-reply@exemple.fr';

    public function testTheOwnerNotificationGoesToTheOwnerAndCarriesTheQualification(): void
    {
        $sent = [];
        $service = $this->service($sent);

        self::assertTrue($service->notify($this->estimate()));

        $payload = $this->lastPayload($sent);
        self::assertSame(self::OWNER_EMAIL, $payload['to'][0]['email']);
        self::assertStringContainsString('Qualification IA', $payload['textContent']);
        self::assertStringContainsString('version 7', $payload['textContent']);
    }

    public function testTheClientAcknowledgementGoesToTheProspect(): void
    {
        $sent = [];
        $service = $this->service($sent);

        self::assertTrue($service->notifyClient($this->estimate()));

        $payload = $this->lastPayload($sent);
        self::assertSame('claire@exemple.fr', $payload['to'][0]['email']);
        self::assertStringContainsString('Votre demande est bien reçue', $payload['subject']);
        self::assertStringContainsString('Bonjour Claire,', $payload['textContent']);
    }

    public function testTheClientAcknowledgementRepeatsTheScopeAndTheAmountShownOnScreen(): void
    {
        $sent = [];
        $this->service($sent)->notifyClient($this->estimate());

        // Amounts carry non-breaking spaces; compare on normalised whitespace.
        $body = (string) preg_replace('/\s+/u', ' ', $this->lastPayload($sent)['textContent']);

        self::assertStringContainsString('Arrêter de refaire la même tâche', $body);
        self::assertStringContainsString('Plusieurs outils à faire communiquer', $body);
        self::assertStringContainsString('à partir de 1 250 €', $body);
        self::assertStringContainsString('Prix de départ pour le périmètre décrit', $body);
        self::assertStringContainsString('- Un tableau de suivi simple', $body);
        self::assertStringContainsString('- Relances automatiques par email', $body);
        self::assertStringContainsString('Excel ou Google Sheets · Gmail ou Outlook', $body);
    }

    /**
     * The prospect must never read the internal qualification: the AI summary,
     * the risk flags and the pricing version belong to the owner's copy only.
     */
    public function testTheClientAcknowledgementLeaksNoInternalQualification(): void
    {
        $sent = [];
        $this->service($sent)->notifyClient($this->estimate());

        $body = $this->lastPayload($sent)['textContent'];

        foreach (['Qualification IA', 'deepseek', 'Le prospect perd', 'version 7', 'Réponses :'] as $internal) {
            self::assertStringNotContainsString($internal, $body, $internal);
        }
    }

    public function testTheClientHtmlLeaksNoInternalQualificationEither(): void
    {
        $sent = [];
        $this->service($sent)->notifyClient($this->estimate());

        $html = $this->lastPayload($sent)['htmlContent'];

        foreach (['Qualification IA', 'deepseek', 'Le prospect perd', 'version 7', 'Réponses'] as $internal) {
            self::assertStringNotContainsString($internal, $html, $internal);
        }

        self::assertStringContainsString('Arrêter de refaire la même tâche', $html);
        self::assertStringContainsString('Prix de départ pour le périmètre décrit', $html);
    }

    public function testTheOwnerHtmlKeepsTheQualification(): void
    {
        $sent = [];
        $this->service($sent)->notify($this->estimate());

        $html = $this->lastPayload($sent)['htmlContent'];

        self::assertStringContainsString('Qualification IA', $html);
        self::assertStringContainsString('Le prospect perd', $html);
        self::assertStringContainsString('version 7', $html);
    }

    public function testAMissingApiKeyReportsFailureInsteadOfThrowing(): void
    {
        $sent = [];
        $service = new QuoteEstimateNotificationService(
            new BrevoEmailSender($this->client($sent), new NullLogger()),
            new BrandedEmailRenderer('Facundo Varas', 'https://varascundo.com'),
            self::OWNER_EMAIL,
            self::SENDER_EMAIL,
            'Facundo',
        );

        $previous = $_SERVER['BREVO_API_KEY'] ?? null;
        $_SERVER['BREVO_API_KEY'] = '';

        try {
            self::assertFalse($service->notifyClient($this->estimate()));
            self::assertSame([], $sent);
        } finally {
            if ($previous === null) {
                unset($_SERVER['BREVO_API_KEY']);
            } else {
                $_SERVER['BREVO_API_KEY'] = $previous;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sent captures every payload handed to Brevo
     */
    private function service(array &$sent): QuoteEstimateNotificationService
    {
        $_SERVER['BREVO_API_KEY'] = 'test-key';

        return new QuoteEstimateNotificationService(
            new BrevoEmailSender($this->client($sent), new NullLogger()),
            new BrandedEmailRenderer('Facundo Varas', 'https://varascundo.com'),
            self::OWNER_EMAIL,
            self::SENDER_EMAIL,
            'Facundo',
        );
    }

    private function client(array &$sent): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse('{"messageId":"1"}', ['http_code' => 201]);
        });
    }

    private function lastPayload(array $sent): array
    {
        self::assertNotSame([], $sent, 'No email was sent.');

        return $sent[count($sent) - 1];
    }

    private function estimate(): QuoteEstimate
    {
        $estimate = new QuoteEstimate();
        $estimate->setServiceKey('automatisation');
        $estimate->setOfferKey('automatisation');
        $estimate->setVariantKey('automatisation-multi-outils');
        $estimate->setPricingVersion(7);
        $estimate->setFullName('Claire Martin');
        $estimate->setEmail('claire@exemple.fr');
        $estimate->setMinimumAmount(1250);
        $estimate->setMaximumAmount(1250);
        $estimate->setCalculationDetail([]);
        $estimate->setAiSummary('Le prospect perd une demi-journée chaque lundi.');
        $estimate->setAiRiskFlags(['Accès aux portails non documenté']);
        $estimate->setAiSource('deepseek');
        $estimate->setAnswers([
            'offerLabel' => 'Arrêter de refaire la même tâche',
            'variantLabel' => 'Plusieurs outils à faire communiquer',
            'pricingMode' => 'from',
            'disclaimer' => 'Prix de départ pour le périmètre décrit ; ce qui sera ajouté ensemble est chiffré à part.',
            'includes' => ['Les informations circulent entre vos outils', 'Un tableau de suivi simple'],
            'selectedOptions' => [['key' => 'relances-auto', 'label' => 'Relances automatiques par email']],
            'toolKeys' => ['tableur', 'email'],
            'toolLabels' => ['Excel ou Google Sheets', 'Gmail ou Outlook'],
            'projectStage' => 'existant',
            'contentReadiness' => 'pret',
            'deadline' => 'flexible',
            'projectDescription' => 'Je recopie les demandes à la main.',
        ]);

        return $estimate;
    }
}
