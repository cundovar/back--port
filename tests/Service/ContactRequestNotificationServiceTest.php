<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ContactRequest;
use App\Service\BrandedEmailRenderer;
use App\Service\BrevoEmailSender;
use App\Service\ContactRequestNotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ContactRequestNotificationServiceTest extends TestCase
{
    private const OWNER_EMAIL = 'contact@exemple.fr';
    private const SENDER_EMAIL = 'no-reply@exemple.fr';

    public function testTheOwnerNotificationGoesToTheOwner(): void
    {
        $sent = [];

        self::assertTrue($this->service($sent)->notify($this->request()));

        $payload = $this->lastPayload($sent);
        self::assertSame(self::OWNER_EMAIL, $payload['to'][0]['email']);
        self::assertStringContainsString('Nouvelle demande de contact — Claire Martin', $payload['subject']);
    }

    public function testTheConfirmationGoesToTheVisitorWhoFilledTheForm(): void
    {
        $sent = [];

        self::assertTrue($this->service($sent)->notifyClient($this->request()));

        $payload = $this->lastPayload($sent);
        self::assertSame('claire@exemple.fr', $payload['to'][0]['email']);
        self::assertSame('Votre demande est bien reçue', $payload['subject']);
        self::assertStringContainsString('Bonjour Claire,', $payload['textContent']);
    }

    public function testTheConfirmationRepeatsEverythingTheVisitorTyped(): void
    {
        $sent = [];
        $this->service($sent)->notifyClient($this->request());

        $body = $this->lastPayload($sent)['textContent'];

        self::assertStringContainsString('Type de mission : Automatisation', $body);
        self::assertStringContainsString('Entreprise : Studio Martin', $body);
        self::assertStringContainsString('Fonction : Gérante', $body);
        self::assertStringContainsString('Budget indicatif : 2000 à 3000 €', $body);
        self::assertStringContainsString('Délai souhaité : Avant juin', $body);
        self::assertStringContainsString('Je recopie les commandes à la main chaque matin.', $body);
    }

    /**
     * The optional fields are the ones a visitor most often leaves empty: the
     * confirmation must still read as a sentence, not as a dangling label.
     */
    public function testTheConfirmationNamesTheFieldsLeftEmpty(): void
    {
        $sent = [];
        $request = $this->request();
        $request->setCompany('');
        $request->setPosition('');
        $request->setBudget(null);
        $request->setTimeline(null);

        $this->service($sent)->notifyClient($request);
        $body = $this->lastPayload($sent)['textContent'];

        self::assertStringContainsString('Entreprise : Non précisée', $body);
        self::assertStringContainsString('Fonction : Non précisée', $body);
        self::assertStringContainsString('Budget indicatif : Non précisé', $body);
        self::assertStringContainsString('Délai souhaité : Non précisé', $body);
    }

    public function testBothEmailsCarryAnHtmlHalfBesideTheText(): void
    {
        $sent = [];
        $service = $this->service($sent);
        $service->notify($this->request());
        $service->notifyClient($this->request());

        foreach ($sent as $index => $payload) {
            self::assertArrayHasKey('htmlContent', $payload, 'email '.$index);
            self::assertArrayHasKey('textContent', $payload, 'email '.$index);
            self::assertStringContainsString('<!doctype html>', $payload['htmlContent']);
            self::assertStringContainsString('Facundo Varas', $payload['htmlContent']);
        }
    }

    public function testTheConfirmationHtmlRepeatsWhatTheVisitorTyped(): void
    {
        $sent = [];
        $this->service($sent)->notifyClient($this->request());

        $html = $this->lastPayload($sent)['htmlContent'];

        self::assertStringContainsString('Type de mission', $html);
        self::assertStringContainsString('Automatisation', $html);
        self::assertStringContainsString('Studio Martin', $html);
        self::assertStringContainsString('Je recopie les commandes à la main chaque matin.', $html);
    }

    /**
     * The message is free text typed by a stranger and lands in both emails: it
     * must reach the mailbox as characters, never as markup.
     */
    public function testMarkupTypedInTheFormNeverReachesTheMailboxAsMarkup(): void
    {
        $sent = [];
        $request = $this->request();
        $request->setMessage('<img src=x onerror="alert(1)"> bonjour');
        $request->setFullName('<b>Claire</b>');

        $service = $this->service($sent);
        $service->notify($request);
        $service->notifyClient($request);

        foreach ($sent as $index => $payload) {
            self::assertStringNotContainsString('<img src=x', $payload['htmlContent'], 'email '.$index);
            self::assertStringNotContainsString('<b>Claire</b>', $payload['htmlContent'], 'email '.$index);
            self::assertStringContainsString('&lt;img src=x', $payload['htmlContent'], 'email '.$index);
        }
    }

    public function testAMissingApiKeyReportsFailureInsteadOfThrowing(): void
    {
        $sent = [];
        $service = $this->build($sent);

        $previous = $_SERVER['BREVO_API_KEY'] ?? null;
        $_SERVER['BREVO_API_KEY'] = '';

        try {
            self::assertFalse($service->notifyClient($this->request()));
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
    private function service(array &$sent): ContactRequestNotificationService
    {
        $_SERVER['BREVO_API_KEY'] = 'test-key';

        return $this->build($sent);
    }

    private function build(array &$sent): ContactRequestNotificationService
    {
        return new ContactRequestNotificationService(
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

    private function request(): ContactRequest
    {
        $request = new ContactRequest();
        $request->setFullName('Claire Martin');
        $request->setEmail('claire@exemple.fr');
        $request->setCompany('Studio Martin');
        $request->setPosition('Gérante');
        $request->setMissionType('Automatisation');
        $request->setBudget('2000 à 3000 €');
        $request->setTimeline('Avant juin');
        $request->setMessage('Je recopie les commandes à la main chaque matin.');

        return $request;
    }
}
