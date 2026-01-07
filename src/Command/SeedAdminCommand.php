<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-admin',
    description: 'Seed or update the admin user.'
)]
final class SeedAdminCommand extends Command
{
    private string $adminEmail;
    private string $adminPassword;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%env(ADMIN_EMAIL)%')] string $adminEmail,
        #[Autowire('%env(ADMIN_PASSWORD)%')] string $adminPassword,
    ) {
        parent::__construct();
        $this->adminEmail = $adminEmail;
        $this->adminPassword = $adminPassword;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(AdminUser::class);
        $user = $repo->findOneBy(['email' => $this->adminEmail]);

        if (!$user) {
            $user = new AdminUser($this->adminEmail, '');
            $this->em->persist($user);
        }

        $hash = $this->hasher->hashPassword($user, $this->adminPassword);
        $user->setPasswordHash($hash);
        $user->setEmail($this->adminEmail);

        $this->em->flush();

        $output->writeln('Admin user seeded.');
        return Command::SUCCESS;
    }
}
