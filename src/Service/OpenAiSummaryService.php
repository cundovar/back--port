<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class OpenAiSummaryService
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly string $openAiModel,
        private readonly int $defaultMaxWords,
    ) {
    }

    /**
     * @param array{repoCount:int,activeRepoCount:int,lastActivity:string,topSkills:array,hiddenSkills:array,evidence:array} $stats
     */
    public function summarize(array $stats, ?int $maxWords = null): string
    {
        if ($this->openAiApiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $maxWords = $maxWords ?? $this->defaultMaxWords;
        $prompt = $this->buildPrompt($stats, $maxWords);

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
                            'content' => 'Tu es un assistant qui resume des stats GitHub pour un portfolio de developpeur. Style: professionnel, concis, oriente preuves. Langue: francais.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 260,
                ],
            ]);

            $payload = $response->toArray(false);
            $content = $payload['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || trim($content) === '') {
                throw new \RuntimeException('OpenAI response was empty.');
            }

            return $this->limitWords(trim($content), $maxWords);
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            throw new \RuntimeException('OpenAI request failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array{repoCount:int,activeRepoCount:int,lastActivity:string,topSkills:array,hiddenSkills:array,evidence:array} $stats
     */
    private function buildPrompt(array $stats, int $maxWords): string
    {
        return sprintf(
            "Donnees GitHub:\n- Repos publics: %d\n- Repos actifs 90j: %d\n- Derniere activite: %s\n- Langages principaux: %s\n\nEcris un resume court (%d mots max) mettant en avant les preuves techniques. Pas de speculation.",
            $stats['repoCount'],
            $stats['activeRepoCount'],
            $stats['lastActivity'],
            $this->formatTopSkills($stats['topSkills']),
            $maxWords
        );
    }

    private function formatTopSkills(array $topSkills): string
    {
        $labels = [];
        foreach ($topSkills as $item) {
            if (isset($item['label']) && is_string($item['label'])) {
                $labels[] = $item['label'];
            }
        }

        return $labels === [] ? 'N/A' : implode(', ', array_slice($labels, 0, 4));
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
