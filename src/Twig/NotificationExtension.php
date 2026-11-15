<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\ActivityRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notification_count', $this->getUnreadCount(...)),
        ];
    }

    public function getUnreadCount(): int
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return 0;
        }

        return $this->activityRepository->countTotalUnreadForUser($user);
    }
}
