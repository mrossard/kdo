<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\ActivityRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class NotificationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function getGlobals(): array
    {
        return [
            'unread_notification_count' => $this->getUnreadCount(),
        ];
    }

    private function getUnreadCount(): int
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return 0;
        }

        return $this->activityRepository->countTotalUnreadForUser($user);
    }
}
