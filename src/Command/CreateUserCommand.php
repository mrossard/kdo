<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a new user')]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $userPasswordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The email of the user')
            ->addArgument('password', InputArgument::OPTIONAL, 'The password of the user')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Set the user as admin')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'The firstname of the user')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'The lastname of the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $isAdmin = $input->getOption('admin');
        $firstname = $input->getOption('firstname');
        $lastname = $input->getOption('lastname');

        if (!$email) {
            $email = $io->ask('Email');
        }

        if (!$password) {
            $password = $io->askHidden('Password');
        }

        if (!$firstname) {
            $firstname = $io->ask('Firstname (optional)', null);
        }

        if (!$lastname) {
            $lastname = $io->ask('Lastname (optional)', null);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setRoles($isAdmin ? ['ROLE_ADMIN'] : []);
        $user->setPassword($this->userPasswordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User %s created successfully.', $email));

        return Command::SUCCESS;
    }
}
