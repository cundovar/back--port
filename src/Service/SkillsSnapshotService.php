<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SkillsSnapshot;
use Doctrine\ORM\EntityManagerInterface;

final class SkillsSnapshotService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GitHubStatsService $gitHubStatsService,
        private readonly OpenAiSummaryService $openAiSummaryService,
        private readonly int $summaryMaxWords,
    ) {
    }

    /**
     * @return array{snapshot: SkillsSnapshot, aiUsed: bool}
     */
    public function generate(bool $skipAi = false): array
    {
        $stats = $this->gitHubStatsService->fetchSnapshot();
        $aiUsed = false;

        if ($skipAi) {
            $summary = $this->buildFallbackSummary($stats);
        } else {
            try {
                $summary = $this->openAiSummaryService->summarize($stats, $this->summaryMaxWords);
                $aiUsed = true;
            } catch (\RuntimeException) {
                $summary = $this->buildFallbackSummary($stats);
            }
        }

        $snapshot = new SkillsSnapshot();
        $snapshot->setSummaryText($summary);
        $snapshot->setTopSkills($stats['topSkills']);
        $snapshot->setHiddenSkills($stats['hiddenSkills']);
        $snapshot->setEvidence($stats['evidence']);

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        return ['snapshot' => $snapshot, 'aiUsed' => $aiUsed];
    }

    /**
     * @param array{repoCount:int,activeRepoCount:int,lastActivity:string,topSkills:array,hiddenSkills:array,evidence:array} $stats
     */
    private function buildFallbackSummary(array $stats): string
    {
        return sprintf(
            'Analyse GitHub: %d repos publics, %d actifs sur 90 jours. Derniere activite: %s. Langages dominants: %s.',
            $stats['repoCount'],
            $stats['activeRepoCount'],
            $stats['lastActivity'],
            $this->formatTopSkills($stats['topSkills'])
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
}
