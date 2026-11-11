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
        /** @var string|null $rawEmail */
        $rawEmail = $input->getArgument('email');
        $email = $rawEmail ?? '';
        /** @var string|null $rawPassword */
        $rawPassword = $input->getArgument('password');
        $password = $rawPassword ?? '';
        $isAdmin = $input->getOption('admin') === true;
        /** @var string|null $firstname */
        $firstname = $input->getOption('firstname');
        /** @var string|null $lastname */
        $lastname = $input->getOption('lastname');

        if ($email === '') {
            /** @var string|null $askedEmail */
            $askedEmail = $io->ask('Email');
            $email = $askedEmail ?? '';
        }

        if ($password === '') {
            /** @var string|null $askedPassword */
            $askedPassword = $io->askHidden('Password');
            $password = $askedPassword ?? '';
        }

        if ($firstname === null || $firstname === '') {
            /** @var string|null $askedFirstname */
            $askedFirstname = $io->ask('Firstname (optional)', null);
            $firstname = $askedFirstname;
        }

        if ($lastname === null || $lastname === '') {
            /** @var string|null $askedLastname */
            $askedLastname = $io->ask('Lastname (optional)', null);
            $lastname = $askedLastname;
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
