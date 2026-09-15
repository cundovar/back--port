<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:create',
    description: 'Create a new admin user',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Admin email address')
            ->addArgument('password', InputArgument::OPTIONAL, 'Admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!$email) {
            $question = new Question('Email: ');
            $email = $this->getHelper('question')->ask($input, $output, $question);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address');
            return Command::FAILURE;
        }

        $existing = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $io->error("Admin with email '{$email}' already exists");
            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if (!$password) {
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $this->getHelper('question')->ask($input, $output, $question);
        }

        if (strlen($password) < 8) {
            $io->error('Password must be at least 8 characters');
            return Command::FAILURE;
        }

        $admin = new AdminUser($email, '');
        $hashedPassword = $this->hasher->hashPassword($admin, $password);
        $admin->setPasswordHash($hashedPassword);

        $this->em->persist($admin);
        $this->em->flush();

        $io->success("Admin '{$email}' created successfully");
        return Command::SUCCESS;
    }
}
