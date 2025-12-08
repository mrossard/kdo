<?php

namespace App\Repository;

use App\Entity\Wish;
use App\Entity\User;
use App\Entity\GiftIdea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Query\Expr\Join;

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

    public function findUserWishesWithGiftIdeaDetails(User $user, bool $showDelivered = false, ?string $sort = 'name', ?string $direction = 'ASC'): array
    {
        $qb = $this->createQueryBuilder('w')
            ->addSelect('gi', 'gp')
            ->leftJoin('w.giftIdeasUsedFor', 'gi')
            ->leftJoin('gi.giftProject', 'gp')
            ->where('w.user = :user')
            ->setParameter('user', $user);

        if (!$showDelivered) {
            // Subquery to find Wishes that HAVE a selected and delivered GiftIdea
            $subquery = $this->getEntityManager()->createQueryBuilder()
                ->select('w_delivered.id')
                ->from(Wish::class, 'w_delivered')
                ->leftJoin('w_delivered.giftIdeasUsedFor', 'gi_delivered')
                ->leftJoin('gi_delivered.giftProject', 'gp_delivered')
                ->where('gi_delivered.status = :selectedStatus')
                ->andWhere('gp_delivered.deliveredAt IS NOT NULL') // This is the key part
                ->andWhere('w_delivered.user = :user'); // Important to scope to current user

            $qb->andWhere($qb->expr()->notIn('w.id', $subquery->getDQL()))
                ->setParameter('selectedStatus', GiftIdea::STATUS_SELECTED);
        }

        $sortField = match ($sort) {
            'price' => 'w.price',
            default => 'w.name',
        };

        return $qb->orderBy($sortField, $direction)
            ->getQuery()
            ->getResult();
    }
}