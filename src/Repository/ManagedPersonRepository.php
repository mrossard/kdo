<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ManagedPerson;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManagedPerson>
 */
class ManagedPersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagedPerson::class);
    }

    /**
     * @return list<ManagedPerson>
     */
    public function findByManager(User $manager): array
    {
        /** @var list<ManagedPerson> $persons */
        $persons = $this
            ->createQueryBuilder('mp')
            ->leftJoin('mp.coManagers', 'cm')
            ->where('mp.manager = :manager OR cm = :manager')
            ->setParameter('manager', $manager)
            ->orderBy('mp.name', 'ASC')
            ->getQuery()
            ->getResult();

        $unique = [];
        foreach ($persons as $person) {
            $id = $person->getId();
            if ($id !== null) {
                $unique[$id] = $person;
            }
        }

        return array_values($unique);
    }

    /**
     * Finds managed persons belonging to (or co-managed by) the given managers (keyed by manager user ID).
     *
     * @param list<User> $managers
     * @return array<int, list<ManagedPerson>>
     */
    public function findByManagersGrouped(array $managers): array
    {
        if ($managers === []) {
            return [];
        }

        $managerIds = [];
        foreach ($managers as $manager) {
            $id = $manager->getId();
            if ($id !== null) {
                $managerIds[$id] = true;
            }
        }

        /** @var list<ManagedPerson> $persons */
        $persons = $this
            ->createQueryBuilder('mp')
            ->leftJoin('mp.coManagers', 'cm')
            ->where('mp.manager IN (:managers) OR cm IN (:managers)')
            ->setParameter('managers', $managers)
            ->orderBy('mp.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($persons as $person) {
            $personId = $person->getId();
            if ($personId === null) {
                continue;
            }
            foreach ($person->getAllManagers() as $personManager) {
                $managerId = $personManager->getId();
                if ($managerId === null || ($managerIds[$managerId] ?? false) === false) {
                    continue;
                }
                $grouped[$managerId][$personId] = $person;
            }
        }

        return array_map(static fn(array $list): array => array_values($list), $grouped);
    }
}
