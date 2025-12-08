<?php

namespace App\Repository;

use App\Entity\GiftProject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
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
     * @return GiftProject[]
     */
    public function findAccessibleProjectsForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.participants', 'p')
            ->where('g.recipient != :user') // Important: Never show if I am the recipient
            ->andWhere('g.owner = :user OR p = :user') // Show if owner OR participant
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds projects where both user1 and user2 are involved (owner, participant, or recipient).
     *
     * @return GiftProject[]
     */
    public function findProjectsInvolvingUsers(User $user1, User $user2): array
    {
        $qb = $this->createQueryBuilder('gp')
                ->leftJoin('gp.participants', 'p')
                ->where('gp.owner = :user1 OR p = :user1')
                ->andWhere('gp.owner = :user2 OR p = :user2')
        ->setParameter('user1', $user1)
        ->setParameter('user2', $user2);

        return $qb->getQuery()->getResult();
    }
}
