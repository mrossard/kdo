<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Comment>
 */
final class CommentVoter extends Voter
{
    public const string EDIT = 'COMMENT_EDIT';
    public const string DELETE = 'COMMENT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], strict: true) && $subject instanceof Comment;
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

        $project = $subject->getProject();
        if ($project === null || $project->getRecipient() === $user) {
            return false;
        }

        if ($attribute === self::EDIT) {
            return $subject->getAuthor() === $user;
        }

        return $subject->getAuthor() === $user || $project->getOwner() === $user;
    }
}
