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

final class SendProjectRemindersCommandTest extends KernelTestCase
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
            $this->em
                ->createQuery("DELETE FROM {$entityClass} e")
                ->execute();
        }
    }

    public function testSendProjectRemindersCommand(): void
    {
        $owner = new User()
            ->setEmail('owner@example.com')
            ->setFirstname('Alice')
            ->setPassword('hashed');
        $participant = new User()
            ->setEmail('participant@example.com')
            ->setFirstname('Bob')
            ->setPassword('hashed');
        $recipient = new User()
            ->setEmail('recipient@example.com')
            ->setFirstname('Charlie')
            ->setPassword('hashed');

        $this->em->persist($owner);
        $this->em->persist($participant);
        $this->em->persist($recipient);

        $targetDate = new \DateTimeImmutable('today')->modify('+7 days');

        $activeProject = new GiftProject()
            ->setName('Anniversaire Charlie')
            ->setOwner($owner)
            ->setRecipient($recipient)
            ->addParticipant($participant)
            ->setTargetDate($targetDate);
        $this->em->persist($activeProject);

        $idea1 = new GiftIdea()
            ->setName('Livre')
            ->setStatus(GiftIdea::STATUS_PROPOSED)
            ->setGiftProject($activeProject)
            ->setProposer($owner);
        $idea2 = new GiftIdea()
            ->setName('Montre')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($owner)
            ->setPurchasePrice('100.00')
            ->setGiftProject($activeProject)
            ->setProposer($owner);
        $this->em->persist($idea1);
        $this->em->persist($idea2);

        // Delivered project on same targetDate should be ignored
        $deliveredProject = new GiftProject()
            ->setName('Projet Déjà Remis')
            ->setOwner($owner)
            ->setTargetDate($targetDate)
            ->setDeliveredAt(new \DateTimeImmutable());
        $this->em->persist($deliveredProject);

        $this->em->flush();

        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:project:send-reminders');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute(['--days' => '7']);
        self::assertSame(Command::SUCCESS, $exitCode);

        $output = (string) preg_replace('/\s+/', ' ', $commandTester->getDisplay());
        self::assertStringContainsString('1 projet(s) trouvé(s)', $output);
        self::assertStringContainsString('2 e-mail(s) de rappel envoyé(s)', $output);

        // When no project matches target date
        $exitCodeEmpty = $commandTester->execute(['--days' => '30']);
        self::assertSame(Command::SUCCESS, $exitCodeEmpty);
        $emptyOutput = (string) preg_replace('/\s+/', ' ', $commandTester->getDisplay());
        self::assertStringContainsString('Aucun projet cadeau actif prévu', $emptyOutput);
    }
}
