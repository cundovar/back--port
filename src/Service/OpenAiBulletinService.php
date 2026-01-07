<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiBulletinService
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly string $openAiModel,
        private readonly int $defaultMaxWords,
        private readonly string $style,
    ) {
    }

    /**
     * @param array<int, array{message:string,date:string}> $commits
     */
    public function generateBulletin(string $projectName, string $stack, string $summary, array $commits): string
    {
        if ($this->openAiApiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $prompt = $this->buildPrompt($projectName, $stack, $summary, $commits);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->openAiApiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModel,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => sprintf('Tu rediges un bulletin de projet pour un portfolio. Style: %s. Langue: francais.', $this->style),
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 200,
                ],
            ]);

            $payload = $response->toArray(false);
            $content = $payload['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || trim($content) === '') {
                throw new \RuntimeException('OpenAI response was empty.');
            }

            return $this->limitWords(trim($content), $this->defaultMaxWords);
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            throw new \RuntimeException('OpenAI request failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<int, array{message:string,date:string}> $commits
     */
    private function buildPrompt(string $projectName, string $stack, string $summary, array $commits): string
    {
        $commitLines = [];
        foreach ($commits as $commit) {
            $line = $commit['message'];
            if ($commit['date'] !== '') {
                $line .= sprintf(' (%s)', substr($commit['date'], 0, 10));
            }
            $commitLines[] = '- ' . $line;
        }

        $commitText = $commitLines !== [] ? implode("\n", $commitLines) : 'Aucun commit recent.';

        return sprintf(
            "Projet: %s\nStack: %s\nResume: %s\nDerniers commits:\n%s\n\nRedige un bulletin d'activite court (%d mots max). 1 paragraphe, orient\u00e9 progres technique, sans exageration.",
            $projectName,
            $stack,
            $summary,
            $commitText,
            $this->defaultMaxWords
        );
    }

    private function limitWords(string $text, int $maxWords): string
    {
        $words = preg_split('/\s+/', trim($text));
        if (!$words || count($words) <= $maxWords) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $maxWords)) . '…';
    }
}
