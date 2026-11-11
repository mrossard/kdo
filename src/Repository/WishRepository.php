<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftIdea;
use App\Entity\User;
use App\Entity\Wish;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        return $this->createUserWishesQueryBuilder($user, $sort, $direction)->getQuery()->getResult();
    }

    /**
     * @return list<Wish>
     */
    public function findUndeliveredUserWishesWithGiftIdeaDetails(
        User $user,
        ?string $sort = 'name',
        ?string $direction = 'ASC',
    ): array {
        $qb = $this->createUserWishesQueryBuilder($user, $sort, $direction);

        $subquery = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select('w_delivered.id')
            ->from(Wish::class, 'w_delivered')
            ->leftJoin('w_delivered.giftIdeasUsedFor', 'gi_delivered')
            ->leftJoin('gi_delivered.giftProject', 'gp_delivered')
            ->where('gi_delivered.status = :selectedStatus')
            ->andWhere('gp_delivered.deliveredAt IS NOT NULL')
            ->andWhere('w_delivered.user = :user');

        $qb->andWhere($qb->expr()->notIn('w.id', $subquery->getDQL()))->setParameter(
            'selectedStatus',
            GiftIdea::STATUS_SELECTED,
        );

        /** @var list<Wish> */
        return $qb->getQuery()->getResult();
    }

    private function createUserWishesQueryBuilder(
        User $user,
        ?string $sort,
        ?string $direction,
    ): \Doctrine\ORM\QueryBuilder {
        $sortField = match ($sort) {
            'price' => 'w.price',
            default => 'w.name',
        };
        $safeDirection = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';

        return $this
            ->createQueryBuilder('w')
            ->addSelect('gi', 'gp')
            ->leftJoin('w.giftIdeasUsedFor', 'gi')
            ->leftJoin('gi.giftProject', 'gp')
            ->where('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy($sortField, $safeDirection);
    }
}
