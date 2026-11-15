<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GiftIdea;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, GiftIdea>
 */
final class GiftIdeaVoter extends Voter
{
    public const string EDIT = 'IDEA_EDIT';
    public const string DELETE = 'IDEA_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], strict: true) && $subject instanceof GiftIdea;
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

        $project = $subject->getGiftProject();
        if ($project === null || $project->getRecipient() === $user) {
            return false;
        }

        return (
            $project->getOwner() === $user
            || $subject->getProposer() === $user
            && $project->getParticipants()->contains($user)
        );
    }
}
