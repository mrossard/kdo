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

#[AsCommand(name: 'app:user:create', description: 'Create a new user or promote an existing user to admin')]
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
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Set the user as admin (also works for existing users)')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'The firstname of the user')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'The lastname of the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|null $rawEmail */
        $rawEmail = $input->getArgument('email');
        $email = $rawEmail ?? '';
        $isAdmin = $input->getOption('admin') === true;

        if ($email === '') {
            /** @var string|null $askedEmail */
            $askedEmail = $io->ask('Email');
            $email = $askedEmail ?? '';
        }

        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('Please provide a valid email address.');

            return Command::FAILURE;
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser instanceof User) {
            if (!$isAdmin) {
                $io->error(sprintf('User %s already exists. Use --admin to grant admin privileges.', $email));

                return Command::FAILURE;
            }

            return $this->promoteToAdmin($io, $existingUser, $email);
        }

        /** @var string|null $rawPassword */
        $rawPassword = $input->getArgument('password');
        $password = $rawPassword ?? '';
        /** @var string|null $firstname */
        $firstname = $input->getOption('firstname');
        /** @var string|null $lastname */
        $lastname = $input->getOption('lastname');

        if ($password === '') {
            /** @var string|null $askedPassword */
            $askedPassword = $io->askHidden('Password');
            $password = $askedPassword ?? '';
        }

        if ($password === '') {
            $io->error('Password cannot be empty.');

            return Command::FAILURE;
        }

        $firstname = $this->resolveOptionalOption($io, $firstname, 'Firstname (optional)');
        $lastname = $this->resolveOptionalOption($io, $lastname, 'Lastname (optional)');

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

    private function promoteToAdmin(SymfonyStyle $io, User $user, string $email): int
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $io->info(sprintf('User %s is already an admin.', $email));

            return Command::SUCCESS;
        }

        $roles[] = 'ROLE_ADMIN';
        $user->setRoles(array_values(array_unique($roles)));
        $this->entityManager->flush();

        $io->success(sprintf('User %s is now an admin.', $email));

        return Command::SUCCESS;
    }

    private function resolveOptionalOption(SymfonyStyle $io, ?string $value, string $prompt): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return (static fn(mixed $asked): ?string => is_string($asked) ? $asked : null)($io->ask($prompt, null));
    }
}
