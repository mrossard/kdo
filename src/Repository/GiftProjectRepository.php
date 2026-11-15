<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftProject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftProject>
 */
class GiftProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftProject::class);
    }

    /**
     * Finds projects where the user is owner OR participant,
     * BUT excludes projects where the user is the recipient (surprise!).
     *
     * @return list<GiftProject>
     */
    public function findAccessibleProjectsForUser(User $user): array
    {
        /** @var list<GiftProject> */
        return $this
            ->createQueryBuilder('g')
            ->leftJoin('g.owner', 'o')
            ->addSelect('o')
            ->leftJoin('g.participants', 'p')
            ->addSelect('p')
            ->leftJoin('g.recipient', 'r')
            ->addSelect('r')
            ->leftJoin('g.managedRecipient', 'mr')
            ->addSelect('mr')
            ->where('g.recipient IS NULL OR g.recipient != :user') // Important: Never show if I am the recipient
            ->andWhere('g.owner = :user OR :user MEMBER OF g.participants') // Show if owner OR participant
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds projects where both user1 and user2 are involved (owner or participant, never recipient).
     *
     * @return list<GiftProject>
     */
    public function findProjectsInvolvingUsers(User $user1, User $user2): array
    {
        $qb = $this
            ->createQueryBuilder('gp')
            ->leftJoin('gp.owner', 'o')
            ->addSelect('o')
            ->leftJoin('gp.participants', 'p')
            ->addSelect('p')
            ->where('gp.owner = :user1 OR :user1 MEMBER OF gp.participants')
            ->andWhere('gp.owner = :user2 OR :user2 MEMBER OF gp.participants')
            ->andWhere('gp.recipient IS NULL OR (gp.recipient != :user1 AND gp.recipient != :user2)')
            ->setParameter('user1', $user1)
            ->setParameter('user2', $user2);

        /** @var list<GiftProject> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<GiftProject>
     */
    public function findProjectsWithTargetDateOn(\DateTimeImmutable $date): array
    {
        /** @var list<GiftProject> */
        return $this
            ->createQueryBuilder('g')
            ->where('g.isArchived = false')
            ->andWhere('g.deliveredAt IS NULL')
            ->andWhere('g.targetDate = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
