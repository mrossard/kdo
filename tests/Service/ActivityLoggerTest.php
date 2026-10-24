<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Activity;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ActivityLoggerTest extends TestCase
{
    public function testLogPersistsActivityAndUpdatesProjectLastActivityAt(): void
    {
        $persistedObject = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persistedObject): bool {
                $persistedObject = $entity;
                return $entity instanceof Activity;
            }));

        $logger = new ActivityLogger($entityManager);

        $project = new GiftProject();
        $project->setName('Anniversaire Paul');
        $user = new User();
        $user->setEmail('alice@example.com');

        self::assertNull($project->getLastActivityAt());

        $logger->log($project, Activity::TYPE_IDEA_ADDED, $user, ['idea_name' => 'Montre']);

        self::assertInstanceOf(Activity::class, $persistedObject);
        self::assertSame($project, $persistedObject->getProject());
        self::assertSame($user, $persistedObject->getUser());
        self::assertSame(Activity::TYPE_IDEA_ADDED, $persistedObject->getType());
        self::assertSame(['idea_name' => 'Montre'], $persistedObject->getMeta());
        self::assertNotNull($project->getLastActivityAt());
    }
}
