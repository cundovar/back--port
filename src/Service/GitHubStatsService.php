<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class GitHubStatsService
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $githubUsername,
        private readonly ?string $githubToken,
    ) {
    }

    /**
     * @return array{repoCount:int,activeRepoCount:int,lastActivity:string,topSkills:array,hiddenSkills:array,evidence:array}
     */
    public function fetchSnapshot(): array
    {
        if ($this->githubUsername === '') {
            throw new \RuntimeException('GITHUB_USERNAME is not configured.');
        }

        $repos = $this->fetchRepos();

        $languageCounts = [];
        $repoCount = 0;
        $activeRepoCount = 0;
        $lastActivity = null;
        $activeThreshold = new \DateTimeImmutable('-90 days');

        foreach ($repos as $repo) {
            if (!is_array($repo) || !empty($repo['fork'])) {
                continue;
            }

            $repoCount++;

            $language = $repo['language'] ?? null;
            if (is_string($language) && $language !== '') {
                $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
            }

            $pushedAt = isset($repo['pushed_at']) ? new \DateTimeImmutable($repo['pushed_at']) : null;
            if ($pushedAt instanceof \DateTimeImmutable) {
                if ($lastActivity === null || $pushedAt > $lastActivity) {
                    $lastActivity = $pushedAt;
                }
                if ($pushedAt >= $activeThreshold) {
                    $activeRepoCount++;
                }
            }
        }

        arsort($languageCounts);
        $topLanguages = array_slice(array_keys($languageCounts), 0, 4);

        $lastActivityLabel = $lastActivity ? $lastActivity->format('Y-m-d') : 'N/A';
        $languageLabel = $topLanguages !== [] ? implode(', ', $topLanguages) : 'N/A';

        $evidence = [
            ['label' => 'Repos publics', 'value' => (string) $repoCount],
            ['label' => 'Repos actifs (90j)', 'value' => (string) $activeRepoCount],
            ['label' => 'Derniere activite', 'value' => $lastActivityLabel],
            ['label' => 'Langages principaux', 'value' => $languageLabel],
        ];

        $topSkills = array_map(
            static fn (string $lang, int $count): array => [
                'label' => $lang,
                'value' => sprintf('%d repos', $count),
            ],
            array_keys($languageCounts),
            array_values($languageCounts),
        );

        return [
            'repoCount' => $repoCount,
            'activeRepoCount' => $activeRepoCount,
            'lastActivity' => $lastActivityLabel,
            'topSkills' => $topSkills,
            'hiddenSkills' => [],
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRepos(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'PortfolioSkillsSnapshot',
        ];

        if ($this->githubToken) {
            $headers['Authorization'] = sprintf('Bearer %s', $this->githubToken);
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('%s/users/%s/repos', self::API_BASE, $this->githubUsername), [
                'headers' => $headers,
                'query' => [
                    'per_page' => 100,
                    'sort' => 'pushed',
                ],
            ]);

            return $response->toArray(false);
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            throw new \RuntimeException('Unable to fetch GitHub data: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
