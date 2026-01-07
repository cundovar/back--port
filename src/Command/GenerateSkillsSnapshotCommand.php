<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SkillsSnapshot;
use App\Service\GitHubStatsService;
use App\Service\OpenAiSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:skills-snapshot',
    description: 'Generates a skills snapshot from GitHub with an OpenAI summary.',
)]
final class GenerateSkillsSnapshotCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GitHubStatsService $gitHubStatsService,
        private readonly OpenAiSummaryService $openAiSummaryService,
        private readonly int $summaryMaxWords,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip OpenAI and use a basic summary.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->gitHubStatsService->fetchSnapshot();

        if ($input->getOption('no-ai')) {
            $summary = $this->buildFallbackSummary($stats);
        } else {
            try {
                $summary = $this->openAiSummaryService->summarize($stats, $this->summaryMaxWords);
            } catch (\RuntimeException $exception) {
                $summary = $this->buildFallbackSummary($stats);
                $output->writeln('<comment>OpenAI failed, fallback summary used.</comment>');
            }
        }

        $snapshot = new SkillsSnapshot();
        $snapshot->setSummaryText($summary);
        $snapshot->setTopSkills($stats['topSkills']);
        $snapshot->setHiddenSkills($stats['hiddenSkills']);
        $snapshot->setEvidence($stats['evidence']);

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        $output->writeln('<info>Skills snapshot generated.</info>');

        return Command::SUCCESS;
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
