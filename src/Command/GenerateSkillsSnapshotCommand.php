<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SkillsSnapshotService;
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
        private readonly SkillsSnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip OpenAI and use a basic summary.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skipAi = (bool) $input->getOption('no-ai');

        $result = $this->snapshotService->generate($skipAi);

        if (!$result['aiUsed'] && !$skipAi) {
            $output->writeln('<comment>OpenAI failed, fallback summary used.</comment>');
        }

        $output->writeln('<info>Skills snapshot generated.</info>');

        return Command::SUCCESS;
    }
}
