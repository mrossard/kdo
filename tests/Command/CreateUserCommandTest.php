<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Activity;
use App\Entity\Comment;
use App\Entity\ContactRequest;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\ProjectReadStatus;
use App\Entity\Reimbursement;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Wish;
use App\Entity\WishlistShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
        $this->purgeDatabase();
    }

    private function purgeDatabase(): void
    {
        foreach ([
            Vote::class,
            Comment::class,
            Activity::class,
            Reimbursement::class,
            ProjectReadStatus::class,
            GiftIdea::class,
            GiftProject::class,
            Wish::class,
            WishlistShare::class,
            ContactRequest::class,
            ResetPasswordRequest::class,
            User::class,
        ] as $entityClass) {
            $this->em->createQuery("DELETE FROM {$entityClass} e")->execute();
        }
    }

    private function createCommandTester(): CommandTester
    {
        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:user:create');

        return new CommandTester($command);
    }

    public function testCreateNewUser(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            'email' => 'new@example.com',
            'password' => 'secret123',
            '--firstname' => 'Alice',
            '--lastname' => 'Dupont',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('User new@example.com created successfully.', $commandTester->getDisplay());

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testPromoteExistingUserToAdmin(): void
    {
        $existingUser = new User()
            ->setEmail('existing@example.com')
            ->setFirstname('Bob')
            ->setPassword('hashed-password');
        $this->em->persist($existingUser);
        $this->em->flush();

        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            'email' => 'existing@example.com',
            '--admin' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('User existing@example.com is now an admin.', $commandTester->getDisplay());

        $this->em->clear();
        $reloadedUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'existing@example.com']);
        self::assertInstanceOf(User::class, $reloadedUser);
        self::assertContains('ROLE_ADMIN', $reloadedUser->getRoles());
        self::assertSame('Bob', $reloadedUser->getFirstname());
        self::assertSame('hashed-password', $reloadedUser->getPassword());
    }

    public function testPromoteAlreadyAdminUser(): void
    {
        $adminUser = new User()
            ->setEmail('admin@example.com')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed-password');
        $this->em->persist($adminUser);
        $this->em->flush();

        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            'email' => 'admin@example.com',
            '--admin' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('User admin@example.com is already an admin.', $commandTester->getDisplay());
    }

    public function testExistingUserWithoutAdminOptionFails(): void
    {
        $existingUser = new User()
            ->setEmail('existing@example.com')
            ->setPassword('hashed-password');
        $this->em->persist($existingUser);
        $this->em->flush();

        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            'email' => 'existing@example.com',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('User existing@example.com already exists.', $commandTester->getDisplay());
    }

    public function testInvalidEmailFails(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            'email' => 'not-an-email',
            'password' => 'secret123',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Please provide a valid email address.', $commandTester->getDisplay());
    }

    public function testEmptyPasswordFails(): void
    {
        $commandTester = $this->createCommandTester();
        $commandTester->setInputs(['']);

        $exitCode = $commandTester->execute([
            'email' => 'valid@example.com',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Password cannot be empty.', $commandTester->getDisplay());
    }
}
