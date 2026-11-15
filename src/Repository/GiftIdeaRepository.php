<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftIdea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftIdea>
 *
 * @method GiftIdea|null find($id, $lockMode = null, $lockVersion = null)
 * @method GiftIdea|null findOneBy(array $criteria, array $orderBy = null)
 * @method GiftIdea[]    findAll()
 * @method GiftIdea[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GiftIdeaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftIdea::class);
    }

    /**
     * @return list<GiftIdea>
     */
    public function findPurchasedByUser(\App\Entity\User $user): array
    {
        /** @var list<GiftIdea> */
        return $this
            ->createQueryBuilder('g')
            ->leftJoin('g.giftProject', 'gp')
            ->addSelect('gp')
            ->where('g.purchasedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
