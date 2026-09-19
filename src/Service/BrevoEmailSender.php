<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class BrevoEmailSender
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $htmlContent is optional and always sent alongside the text part: a client
     * that refuses HTML, and the plain-text preview inboxes show, both still get
     * the whole message.
     */
    public function send(string $apiKey, string $from, string $to, string $subject, string $textContent, ?string $htmlContent = null): bool
    {
        if ($apiKey === '') {
            $this->logger->warning('Brevo notification skipped: BREVO_API_KEY is not configured.');

            return false;
        }

        $payload = [
            'sender' => ['email' => $from],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'textContent' => $textContent,
        ];

        if ($htmlContent !== null && $htmlContent !== '') {
            $payload['htmlContent'] = $htmlContent;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $apiKey,
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            $this->logger->error('Brevo notification failed.', ['status_code' => $statusCode]);
        } catch (Throwable $exception) {
            $this->logger->error('Brevo notification failed.', ['exception' => $exception]);
        }

        return false;
    }
}
