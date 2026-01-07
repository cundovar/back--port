<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:seed-content',
    description: 'Seed content payload from frontend JSON.'
)]
final class SeedContentCommand extends Command
{
    private string $contentPath;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        parent::__construct();
        $this->contentPath = '/frontend/src/data/content.json';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fs = new Filesystem();
        if (!$fs->exists($this->contentPath)) {
            $output->writeln('content.json not found.');
            return Command::FAILURE;
        }

        $raw = file_get_contents($this->contentPath);
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            $output->writeln('content.json invalid.');
            return Command::FAILURE;
        }

        $repo = $this->em->getRepository(Content::class);
        $content = $repo->findOneBy([]) ?? new Content();
        $content->setPayload($data);

        $this->em->persist($content);
        $this->em->flush();

        $output->writeln('Content seeded.');
        return Command::SUCCESS;
    }
}
