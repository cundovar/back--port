<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GitHubRepoActivityService
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $githubToken,
    ) {
    }

    /**
     * @return array<int, array{message:string,date:string}>
     */
    public function fetchRecentCommits(string $repoUrl, int $limit = 5): array
    {
        $repo = $this->parseRepoSlug($repoUrl);
        if ($repo === null) {
            return [];
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'PortfolioProjectBulletins',
        ];

        if ($this->githubToken) {
            $headers['Authorization'] = sprintf('Bearer %s', $this->githubToken);
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('%s/repos/%s/commits', self::API_BASE, $repo), [
                'headers' => $headers,
                'query' => [
                    'per_page' => max(1, min(10, $limit)),
                ],
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            return [];
        }

        $commits = [];
        foreach ($payload as $item) {
            if (!isset($item['commit']['message'])) {
                continue;
            }
            $message = trim((string) $item['commit']['message']);
            $date = isset($item['commit']['committer']['date']) ? (string) $item['commit']['committer']['date'] : '';
            $commits[] = [
                'message' => strtok($message, "\n") ?: $message,
                'date' => $date,
            ];
        }

        return $commits;
    }

    private function parseRepoSlug(string $repoUrl): ?string
    {
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            return null;
        }

        if (!preg_match('~github\.com/([^/]+/[^/]+)(?:\.git)?~i', $repoUrl, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
