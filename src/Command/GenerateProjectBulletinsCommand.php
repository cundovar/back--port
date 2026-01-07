<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Project;
use App\Service\GitHubRepoActivityService;
use App\Service\OpenAiBulletinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:project-bulletins',
    description: 'Generates AI bulletins for projects using GitHub activity.',
)]
final class GenerateProjectBulletinsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GitHubRepoActivityService $gitHubRepoActivityService,
        private readonly OpenAiBulletinService $openAiBulletinService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of projects processed', 10)
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip OpenAI and keep existing bulletins.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $limit = $limit > 0 ? $limit : 10;

        /** @var Project[] $projects */
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['updatedAt' => 'DESC'], $limit);

        if ($projects === []) {
            $output->writeln('<comment>No projects found.</comment>');
            return Command::SUCCESS;
        }

        if ($input->getOption('no-ai')) {
            $output->writeln('<comment>OpenAI disabled. Nothing to update.</comment>');
            return Command::SUCCESS;
        }

        foreach ($projects as $project) {
            $commits = $this->gitHubRepoActivityService->fetchRecentCommits($project->getRepoUrl(), 5);

            try {
                $bulletin = $this->openAiBulletinService->generateBulletin(
                    $project->getName(),
                    $project->getStack(),
                    $project->getSummary(),
                    $commits
                );
            } catch (\RuntimeException $exception) {
                $output->writeln(sprintf('<comment>Skipped %s (OpenAI error)</comment>', $project->getName()));
                continue;
            }

            $project->setBulletin($bulletin);
            $project->touch();
            $this->entityManager->persist($project);

            $output->writeln(sprintf('<info>Updated bulletin: %s</info>', $project->getName()));
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
