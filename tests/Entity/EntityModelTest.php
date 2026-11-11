<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Activity;
use App\Entity\Comment;
use App\Entity\ContactRequest;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\Vote;
use App\Enum\GiftIdeaStatus;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\GiftIdeaVoter;
use App\Security\Voter\GiftProjectVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class EntityModelTest extends TestCase
{
    public function testUserDisplayNameFallbacks(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');

        self::assertSame('alice@example.com', $user->getDisplayName());

        $user->setFirstname('Alice');
        self::assertSame('Alice', $user->getDisplayName());

        $user->setLastname('Dupont');
        self::assertSame('Alice Dupont', $user->getDisplayName());
    }

    public function testUserRolesAlwaysIncludeRoleUser(): void
    {
        $user = new User();
        self::assertContains('ROLE_USER', $user->getRoles());

        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $roles = $user->getRoles();
        self::assertCount(2, $roles);
        self::assertContains('ROLE_ADMIN', $roles);
    }

    public function testGiftProjectRecipientDisplayName(): void
    {
        $project = new GiftProject();
        self::assertSame('Anonyme', $project->getRecipientDisplayName());

        $project->setRecipientName('Mamie');
        self::assertSame('Mamie', $project->getRecipientDisplayName());

        $recipient = new User();
        $recipient->setEmail('mamie@example.com')->setFirstname('Jeanne');
        $project->setRecipient($recipient);
        self::assertSame('Jeanne', $project->getRecipientDisplayName());
    }

    public function testGiftIdeaVotingLogic(): void
    {
        $idea = new GiftIdea();
        $idea->setName('Livre');

        $alice = new User();
        $alice->setEmail('alice@example.com');

        $bob = new User();
        $bob->setEmail('bob@example.com');

        self::assertSame(0, $idea->getVoteCount());
        self::assertFalse($idea->hasVoteFromUser($alice));

        $vote = new Vote();
        $vote->setUser($alice);
        $idea->addVote($vote);

        self::assertSame(1, $idea->getVoteCount());
        self::assertTrue($idea->hasVoteFromUser($alice));
        self::assertFalse($idea->hasVoteFromUser($bob));

        $idea->removeVote($vote);
        self::assertSame(0, $idea->getVoteCount());
        self::assertFalse($idea->hasVoteFromUser($alice));
    }

    public function testContactRequestRecipientDisplayName(): void
    {
        $request = new ContactRequest();
        self::assertSame('', $request->getRecipientDisplayName());

        $request->setRecipientEmail('invite@example.com');
        self::assertSame('invite@example.com', $request->getRecipientDisplayName());

        $user = new User();
        $user->setEmail('invite@example.com')->setFirstname('Invite');
        $request->setRecipient($user);
        self::assertSame('Invite', $request->getRecipientDisplayName());
    }

    public function testActivityMessagesAndIcons(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com')->setFirstname('Alice');

        $activity = new Activity();
        $activity->setUser($user);

        $activity->setType(Activity::TYPE_IDEA_ADDED)->setMeta(['idea_name' => 'Vélo']);
        self::assertSame("Alice a proposé l'idée « Vélo »", $activity->getMessage());
        self::assertSame('material-symbols:lightbulb', $activity->getIcon());

        $activity->setType(Activity::TYPE_IDEA_PURCHASED)->setMeta(['idea_name' => 'Vélo', 'price' => '120']);
        self::assertSame('Alice a acheté « Vélo » pour 120 €', $activity->getMessage());
        self::assertSame('material-symbols:shopping-cart', $activity->getIcon());

        $activity->setType(Activity::TYPE_PROJECT_DELIVERED);
        self::assertSame('Alice a marqué le projet comme remis', $activity->getMessage());
        self::assertSame('material-symbols:redeem', $activity->getIcon());

        $activity->setType('unknown_type');
        self::assertSame('Action inconnue', $activity->getMessage());
        self::assertSame('material-symbols:info', $activity->getIcon());

        // Test all remaining activity types and icons
        $types = [
            Activity::TYPE_IDEA_SELECTED => 'material-symbols:check-circle',
            Activity::TYPE_IDEA_DISCARDED => 'material-symbols:cancel',
            Activity::TYPE_COMMENT_ADDED => 'material-symbols:comment',
            Activity::TYPE_PARTICIPANT_ADDED => 'material-symbols:person-add',
            Activity::TYPE_PARTICIPANT_REMOVED => 'material-symbols:person-remove',
            Activity::TYPE_PROJECT_LOCKED => 'material-symbols:lock',
            Activity::TYPE_PROJECT_UNLOCKED => 'material-symbols:lock-open',
            Activity::TYPE_REIMBURSEMENT_CREATED => 'material-symbols:euro',
        ];
        foreach ($types as $type => $expectedIcon) {
            $activity->setType($type)->setMeta(['idea_name' => 'Test', 'amount' => '10']);
            self::assertNotEmpty($activity->getMessage());
            self::assertSame($expectedIcon, $activity->getIcon());
        }
    }

    public function testUserAndProjectCollectionsAndRelationships(): void
    {
        $user = new User();
        $user->setEmail('test@example.com')->setFirstname('Jean')->setLastname('Valjean');
        $user->setUiPreferences(['theme' => 'dark']);
        $user->eraseCredentials();

        self::assertSame('test@example.com', $user->getUserIdentifier());
        self::assertSame('Jean', $user->getFirstname());
        self::assertSame('Valjean', $user->getLastname());
        self::assertSame(['theme' => 'dark'], $user->getUiPreferences());

        $wish = new \App\Entity\Wish();
        $wish->setName('Guitare')->setDescription('Acoustique')->setUrl('https://example.com')->setPrice('199.99');
        $user->addWish($wish);
        self::assertCount(1, $user->getWishes());
        self::assertSame($user, $wish->getUser());
        $user->removeWish($wish);
        self::assertCount(0, $user->getWishes());

        $share = new \App\Entity\WishlistShare();
        $user->addSentShare($share);
        $user->addReceivedShare($share);
        self::assertCount(1, $user->getSentShares());
        self::assertCount(1, $user->getReceivedShares());
        $user->removeSentShare($share);
        $user->removeReceivedShare($share);
        self::assertCount(0, $user->getSentShares());

        $idea = new GiftIdea();
        $idea->setName('Idée')->setDescription('Desc')->setUrl('https://example.com');
        $user->addProposedGiftIdea($idea);
        self::assertCount(1, $user->getProposedGiftIdeas());
        $user->removeProposedGiftIdea($idea);
        self::assertCount(0, $user->getProposedGiftIdeas());

        $comment = new \App\Entity\Comment();
        $comment->setContent('Super !');
        $idea->addComment($comment);
        $user->addComment($comment);
        self::assertCount(1, $idea->getComments());
        self::assertCount(1, $user->getComments());
        $idea->removeComment($comment);
        $user->removeComment($comment);

        $readStatus = new \App\Entity\ProjectReadStatus();
        $user->addProjectReadStatus($readStatus);
        self::assertCount(1, $user->getProjectReadStatuses());
        $user->removeProjectReadStatus($readStatus);

        $contactReq = new ContactRequest();
        $user->addSentContactRequest($contactReq);
        $user->addReceivedContactRequest($contactReq);
        self::assertCount(1, $user->getSentContactRequests());
        self::assertCount(1, $user->getReceivedContactRequests());
        $user->removeSentContactRequest($contactReq);
        $user->removeReceivedContactRequest($contactReq);

        $activity = new Activity();
        $user->addActivity($activity);
        self::assertCount(1, $user->getActivities());
        $user->removeActivity($activity);

        $vote = new Vote();
        $user->addVote($vote);
        self::assertCount(1, $user->getVotes());
        $user->removeVote($vote);

        $project = new GiftProject();
        $project->setName('Proj')->setDescription('Desc')->setTargetDate(new \DateTimeImmutable());
        $project->addGiftIdea($idea);
        $project->addActivity($activity);
        $reimb = new \App\Entity\Reimbursement();
        $project->addReimbursement($reimb);
        self::assertCount(1, $project->getGiftIdeas());
        self::assertCount(1, $project->getActivities());
        self::assertCount(1, $project->getReimbursements());
        $project->removeGiftIdea($idea);
        $project->removeActivity($activity);
        $project->removeReimbursement($reimb);
        self::assertCount(0, $project->getGiftIdeas());
    }

    public function testSecurityVotersAndEnumWeights(): void
    {
        self::assertSame(1, GiftIdeaStatus::SELECTED->getSortWeight());
        self::assertSame(2, GiftIdeaStatus::PROPOSED->getSortWeight());
        self::assertSame(3, GiftIdeaStatus::DISCARDED->getSortWeight());

        $owner = new User()->setEmail('owner@example.com');
        $participant = new User()->setEmail('participant@example.com');
        $recipient = new User()->setEmail('recipient@example.com');
        $outsider = new User()->setEmail('outsider@example.com');

        $project = new GiftProject()
            ->setName('Cadeau')
            ->setOwner($owner)
            ->setRecipient($recipient)
            ->addParticipant($participant);

        $idea = new GiftIdea()
            ->setName('Livre')
            ->setGiftProject($project)
            ->setProposer($participant);

        $comment = new Comment()
            ->setContent('Super')
            ->setGiftIdea($idea)
            ->setAuthor($participant);

        $projectVoter = new GiftProjectVoter();
        $ideaVoter = new GiftIdeaVoter();
        $commentVoter = new CommentVoter();

        $ownerToken = new UsernamePasswordToken($owner, 'main', $owner->getRoles());
        $participantToken = new UsernamePasswordToken($participant, 'main', $participant->getRoles());
        $recipientToken = new UsernamePasswordToken($recipient, 'main', $recipient->getRoles());
        $outsiderToken = new UsernamePasswordToken($outsider, 'main', $outsider->getRoles());

        // Project Voter
        self::assertSame(VoterInterface::ACCESS_GRANTED, $projectVoter->vote($ownerToken, $project, [GiftProjectVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $projectVoter->vote($ownerToken, $project, [GiftProjectVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $projectVoter->vote($participantToken, $project, [GiftProjectVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $projectVoter->vote($participantToken, $project, [GiftProjectVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $projectVoter->vote($recipientToken, $project, [GiftProjectVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $projectVoter->vote($outsiderToken, $project, [GiftProjectVoter::VIEW]));

        // Idea Voter
        self::assertSame(VoterInterface::ACCESS_GRANTED, $ideaVoter->vote($ownerToken, $idea, [GiftIdeaVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $ideaVoter->vote($participantToken, $idea, [GiftIdeaVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $ideaVoter->vote($outsiderToken, $idea, [GiftIdeaVoter::EDIT]));

        // Comment Voter
        self::assertSame(VoterInterface::ACCESS_GRANTED, $commentVoter->vote($participantToken, $comment, [CommentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $commentVoter->vote($ownerToken, $comment, [CommentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $commentVoter->vote($ownerToken, $comment, [CommentVoter::DELETE]));
    }
}
