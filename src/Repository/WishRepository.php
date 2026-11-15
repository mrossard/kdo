<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftIdea;
use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Entity\Wish;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wish>
 *
 * @method Wish|null find($id, $lockMode = null, $lockVersion = null)
 * @method Wish|null findOneBy(array $criteria, array $orderBy = null)
 * @method Wish[]    findAll()
 * @method Wish[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WishRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wish::class);
    }

    /**
     * @return list<Wish>
     */
    public function findAllUserWishesWithGiftIdeaDetails(
        User $user,
        ?string $sort = 'name',
        ?string $direction = 'ASC',
    ): array {
        /** @var list<Wish> */
        return $this->createOwnerWishesQueryBuilder($user, $sort, $direction)->getQuery()->getResult();
    }

    /**
     * @return list<Wish>
     */
    public function findUndeliveredUserWishesWithGiftIdeaDetails(
        User $user,
        ?string $sort = 'name',
        ?string $direction = 'ASC',
    ): array {
        return $this->findUndeliveredOwnerWishesWithGiftIdeaDetails($user, $sort, $direction);
    }

    /**
     * @return list<Wish>
     */
    public function findAllManagedPersonWishesWithGiftIdeaDetails(
        ManagedPerson $managedPerson,
        ?string $sort = 'name',
        ?string $direction = 'ASC',
    ): array {
        /** @var list<Wish> */
        return $this->createOwnerWishesQueryBuilder($managedPerson, $sort, $direction)->getQuery()->getResult();
    }

    /**
     * @return list<Wish>
     */
    public function findUndeliveredManagedPersonWishesWithGiftIdeaDetails(
        ManagedPerson $managedPerson,
        ?string $sort = 'name',
        ?string $direction = 'ASC',
    ): array {
        return $this->findUndeliveredOwnerWishesWithGiftIdeaDetails($managedPerson, $sort, $direction);
    }

    /**
     * @return list<Wish>
     */
    private function findUndeliveredOwnerWishesWithGiftIdeaDetails(
        User|ManagedPerson $owner,
        ?string $sort,
        ?string $direction,
    ): array {
        $qb = $this->createOwnerWishesQueryBuilder($owner, $sort, $direction);
        $ownerField = $owner instanceof ManagedPerson ? 'w_delivered.managedPerson' : 'w_delivered.user';

        $subquery = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('w_delivered.id')
            ->from(Wish::class, 'w_delivered')
            ->leftJoin('w_delivered.giftIdeasUsedFor', 'gi_delivered')
            ->leftJoin('gi_delivered.giftProject', 'gp_delivered')
            ->where('gi_delivered.status = :selectedStatus')
            ->andWhere('gp_delivered.deliveredAt IS NOT NULL')
            ->andWhere("{$ownerField} = :owner");

        $qb->andWhere($qb->expr()->notIn('w.id', $subquery->getDQL()))->setParameter(
            'selectedStatus',
            GiftIdea::STATUS_SELECTED,
        );

        /** @var list<Wish> */
        return $qb->getQuery()->getResult();
    }

    private function createOwnerWishesQueryBuilder(
        User|ManagedPerson $owner,
        ?string $sort,
        ?string $direction,
    ): QueryBuilder {
        $sortField = match ($sort) {
            'price' => 'w.price',
            'priority' => 'w.priority',
            default => 'w.name',
        };
        $safeDirection = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
        $ownerField = $owner instanceof ManagedPerson ? 'w.managedPerson' : 'w.user';

        return $this
            ->createQueryBuilder('w')
            ->addSelect('gi', 'gp')
            ->leftJoin('w.giftIdeasUsedFor', 'gi')
            ->leftJoin('gi.giftProject', 'gp')
            ->where("{$ownerField} = :owner")
            ->setParameter('owner', $owner)
            ->orderBy($sortField, $safeDirection);
    }
}
