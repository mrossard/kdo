<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\GiftProject;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ActivityLogger
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function log(GiftProject $project, string $type, User $user, array $meta = []): void
    {
        $activity = new Activity();
        $activity->setProject($project);
        $activity->setType($type);
        $activity->setUser($user);
        $activity->setMeta($meta);

        $this->entityManager->persist($activity);

        $project->setLastActivityAt(new \DateTimeImmutable());
    }
}
