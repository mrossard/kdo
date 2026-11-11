<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WishlistShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WishlistShare>
 */
class WishlistShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistShare::class);
    }

    /**
     * Finds all users who have a mutual wishlist share with the given user in a single query.
     *
     * @return list<User>
     */
    public function findMutualContactsForUser(User $user): array
    {
        /** @var list<WishlistShare> $shares */
        $shares = $this
            ->createQueryBuilder('ws')
            ->innerJoin('ws.recipient', 'u')
            ->innerJoin(
                WishlistShare::class,
                'ws_reverse',
                'WITH',
                'ws_reverse.owner = ws.recipient AND ws_reverse.recipient = ws.owner',
            )
            ->addSelect('u')
            ->where('ws.owner = :user')
            ->andWhere('ws.recipient != :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $contacts = [];
        foreach ($shares as $share) {
            $contact = $share->getRecipient();
            $contactId = $contact?->getId();
            if ($contact !== null && $contactId !== null) {
                $contacts[$contactId] = $contact;
            }
        }

        $result = array_values($contacts);
        usort($result, static fn(User $a, User $b) => strcmp($a->getDisplayName(), $b->getDisplayName()));

        return $result;
    }

    public function countMutualContactsForUser(User $user): int
    {
        return count($this->findMutualContactsForUser($user));
    }
}
