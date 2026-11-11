<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GiftProject;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, GiftProject>
 */
final class GiftProjectVoter extends Voter
{
    public const string VIEW = 'PROJECT_VIEW';
    public const string MANAGE = 'PROJECT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], strict: true) && $subject instanceof GiftProject;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        #[\SensitiveParameter]
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($attribute === self::MANAGE) {
            return $subject->getOwner() === $user;
        }

        // PROJECT_VIEW: never allow the recipient to view a surprise project
        if ($subject->getRecipient() === $user) {
            return false;
        }

        return $subject->getOwner() === $user || $subject->getParticipants()->contains($user);
    }
}
