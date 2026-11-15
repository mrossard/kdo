<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Activity;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GiftProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryBugsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE gift_idea_contribution, vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
        );
    }

    private function createUser(string $email, string $firstname): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setPassword('hashed_password');
        $this->em->persist($user);

        return $user;
    }

    public function testCountUnreadActivitiesDoesNotMultiplyByParticipantCount(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $p1 = $this->createUser('p1@example.com', 'P1');
        $p2 = $this->createUser('p2@example.com', 'P2');
        $p3 = $this->createUser('p3@example.com', 'P3');

        $project = new GiftProject();
        $project
            ->setName('Anniversaire')
            ->setOwner($owner)
            ->addParticipant($p1)
            ->addParticipant($p2)
            ->addParticipant($p3);
        $this->em->persist($project);

        $activity = new Activity();
        $activity
            ->setProject($project)
            ->setUser($p1)
            ->setType(Activity::TYPE_IDEA_ADDED);
        $this->em->persist($activity);
        $this->em->flush();

        /** @var ActivityRepository $activityRepo */
        $activityRepo = $this->em->getRepository(Activity::class);

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        self::assertSame(1, $activityRepo->countTotalUnreadForUser($owner));
        self::assertSame([$projectId => 1], $activityRepo->countUnreadPerProjectForUser($owner));
    }

    public function testFindProjectsInvolvingUsersFindsTwoNonOwnerParticipants(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $recipient = $this->createUser('recipient@example.com', 'Recipient');

        $project = new GiftProject();
        $project
            ->setName('Cadeau Commun')
            ->setOwner($owner)
            ->setRecipient($recipient)
            ->addParticipant($alice)
            ->addParticipant($bob);
        $this->em->persist($project);
        $this->em->flush();

        /** @var GiftProjectRepository $projectRepo */
        $projectRepo = $this->em->getRepository(GiftProject::class);

        $found = $projectRepo->findProjectsInvolvingUsers($alice, $bob);
        self::assertCount(1, $found);
        self::assertSame($project->getId(), $found[0]->getId());

        // Never return a project where one of the users is the recipient
        self::assertSame([], $projectRepo->findProjectsInvolvingUsers($alice, $recipient));
    }
}
