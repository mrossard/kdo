<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class GiftProjectControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean database tables before each test
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'TRUNCATE TABLE vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
        );
    }

    private function createUser(string $email, string $firstname): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setPassword('hashed_password');
        $this->em->persist($user);

        return $user;
    }

    private function getCsrfToken(string $tokenId): string
    {
        /** @var CsrfTokenManagerInterface $tokenManager */
        $tokenManager = static::getContainer()->get(CsrfTokenManagerInterface::class);

        return $tokenManager->getToken($tokenId)->getValue();
    }

    public function testRecipientCannotViewSurpriseProject(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $recipient = $this->createUser('recipient@example.com', 'Recipient');

        $project = new GiftProject();
        $project->setName('Cadeau Surprise')->setOwner($owner)->setRecipient($recipient);
        $this->em->persist($project);
        $this->em->flush();

        $this->client->loginUser($recipient);
        $this->client->request('GET', '/project/' . $project->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonParticipantCannotViewProject(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $outsider = $this->createUser('outsider@example.com', 'Outsider');

        $project = new GiftProject();
        $project->setName('Cadeau Privé')->setOwner($owner);
        $this->em->persist($project);
        $this->em->flush();

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/project/' . $project->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerAndParticipantCanViewProjectAndVoteOnIdea(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $participant = $this->createUser('participant@example.com', 'Participant');

        $project = new GiftProject();
        $project->setName('Anniversaire')->setOwner($owner)->addParticipant($participant);
        $this->em->persist($project);

        $idea = new GiftIdea();
        $idea
            ->setName('Appareil Photo')
            ->setPrice('150.00')
            ->setGiftProject($project)
            ->setProposer($owner);
        $project->addGiftIdea($idea);
        $this->em->persist($idea);
        $this->em->flush();

        $this->client->loginUser($participant);
        $crawler = $this->client->request('GET', '/project/' . $project->getId() . '?tab=ideas');
        self::assertResponseIsSuccessful();

        $voteVals = json_decode(
            $crawler->filter('[hx-post$="/vote"]')->attr('hx-vals') ?? '{}',
            true,
        );

        // Toggle vote on idea
        $this->client->request('POST', '/project/idea/' . $idea->getId() . '/vote', [
            '_token' => $voteVals['_token'] ?? '',
        ], [], [
            'HTTP_HX-Request' => 'true',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloadedIdea = $this->em->getRepository(GiftIdea::class)->find($idea->getId());
        self::assertNotNull($reloadedIdea);
        self::assertSame(1, $reloadedIdea->getVoteCount());
    }

    public function testDeliveredProjectFreezesIdeasButAllowsReimbursementAndArchive(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $participant = $this->createUser('participant@example.com', 'Participant');

        $project = new GiftProject();
        $project
            ->setName('Projet Remis')
            ->setOwner($owner)
            ->addParticipant($participant)
            ->setLocked(true)
            ->setDeliveredAt(new \DateTimeImmutable());
        $this->em->persist($project);

        $idea = new GiftIdea();
        $idea
            ->setName('Montre')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setGiftProject($project)
            ->setProposer($owner)
            ->setPurchasedBy($owner)
            ->setPurchasePrice('100.00');
        $this->em->persist($idea);
        $this->em->flush();

        $this->client->loginUser($owner);

        // 1. Unlocking a delivered project is denied and redirects with flash warning
        $this->client->request('POST', '/project/' . $project->getId() . '/unlock');
        self::assertResponseRedirects('/project/' . $project->getId() . '?tab=ideas');

        $this->em->clear();
        $reloadedProject = $this->em->getRepository(GiftProject::class)->find($project->getId());
        self::assertTrue($reloadedProject?->isLocked());

        // 2. Non-HTMX GET on editGiftIdea when delivered redirects to show
        $this->client->request('GET', '/project/idea/' . $idea->getId() . '/edit');
        self::assertResponseRedirects('/project/' . $project->getId() . '?tab=ideas');

        // 3. Reimbursements remain allowed after delivery
        $crawler = $this->client->request('GET', '/project/' . $project->getId() . '?tab=financial', [], [], [
            'HTTP_HX-Request' => 'true',
        ]);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/project/' . $project->getId() . '/reimburse', [
            '_token' => $token,
            'debtor_id' => $participant->getId(),
            'amount' => '50',
        ]);
        self::assertResponseRedirects('/project/' . $project->getId() . '?tab=financial');

        $this->em->clear();
        $reloadedProject = $this->em->getRepository(GiftProject::class)->find($project->getId());
        self::assertCount(1, $reloadedProject->getReimbursements());

        // 4. Archiving remains allowed after delivery
        $detailsCrawler = $this->client->request('GET', '/project/' . $project->getId() . '?tab=details', [], [], [
            'HTTP_HX-Request' => 'true',
        ]);
        $archiveToken = $detailsCrawler->filter('form[action$="/archive"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/project/' . $project->getId() . '/archive', [
            '_token' => $archiveToken,
        ]);
        self::assertResponseRedirects('/project/?archived=1');

        $this->em->clear();
        $reloadedProject = $this->em->getRepository(GiftProject::class)->find($project->getId());
        self::assertTrue($reloadedProject?->isArchived());
    }

    public function testFullProjectLifecycleCreateEditIdeasCommentsLockPurchaseDeliver(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $participant = $this->createUser('participant@example.com', 'Participant');
        $recipient = $this->createUser('recipient@example.com', 'Recipient');

        // Mutual shares so Owner can invite Participant and pick Recipient
        $share1 = new \App\Entity\WishlistShare()->setOwner($recipient)->setRecipient($owner);
        $share2 = new \App\Entity\WishlistShare()->setOwner($participant)->setRecipient($owner);
        $this->em->persist($share1);
        $this->em->persist($share2);

        $wish = new \App\Entity\Wish()->setName('Souhait Destinataire')->setPrice('45.00')->setUser($recipient);
        $this->em->persist($wish);
        $this->em->flush();

        $this->client->loginUser($owner);

        // 1. Index page
        $this->client->request('GET', '/project/');
        self::assertResponseIsSuccessful();

        // 2. Create project via form
        $crawler = $this->client->request('GET', '/project/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Créer le projet')->form([
            'gift_project[name]' => 'Anniversaire Recipient',
            'gift_project[description]' => 'Description initiale',
            'gift_project[recipient]' => (string) $recipient->getId(),
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        /** @var GiftProject $project */
        $project = $this->em->getRepository(GiftProject::class)->findOneBy(['name' => 'Anniversaire Recipient']);
        self::assertNotNull($project);
        $projectId = $project->getId();

        // 3. Edit project
        $crawler = $this->client->request('GET', "/project/{$projectId}/edit");
        self::assertResponseIsSuccessful();
        $editForm = $crawler->selectButton('Enregistrer les modifications')->form([
            'gift_project[name]' => 'Anniversaire Modifié',
            'gift_project[description]' => 'Nouvelle description',
            'gift_project[recipient]' => (string) $recipient->getId(),
        ]);
        $this->client->submit($editForm);
        self::assertResponseRedirects("/project/{$projectId}");

        // 4. Show project tabs (HTMX & non-HTMX) + invite participant
        $crawler = $this->client->request('GET', "/project/{$projectId}");
        self::assertResponseIsSuccessful();
        $inviteForm = $crawler->selectButton('Inviter')->form([
            'add_participant[participant]' => (string) $participant->getId(),
        ]);
        $this->client->submit($inviteForm);
        self::assertResponseRedirects("/project/{$projectId}?tab=details");

        // Test HTMX tabs: details, ideas (with sorts), liste_souhaits, financial, activite
        foreach (['details', 'ideas', 'liste_souhaits', 'financial', 'activite'] as $tab) {
            $this->client->request('GET', "/project/{$projectId}?tab={$tab}", [], [], ['HTTP_HX-Request' => 'true']);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', "/project/{$projectId}?tab=ideas&sort=price_asc", [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', "/project/{$projectId}?tab=ideas&sort=price_desc", [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        // 5. Get new idea form via HTMX and submit new idea via show
        $this->client->request('GET', "/project/{$projectId}/new-idea-form");
        self::assertResponseIsSuccessful();

        // Copy wish to idea
        $wishTabCrawler = $this->client->request('GET', "/project/{$projectId}?tab=liste_souhaits", [], [], ['HTTP_HX-Request' => 'true']);
        $copyToken = $wishTabCrawler->filter('form[action*="/copy-wish/"] input[name="_token"]')->attr('value');
        $this->client->request('POST', "/project/{$projectId}/copy-wish/{$wish->getId()}", ['_token' => $copyToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        // Duplicate copy wish is rejected with warning
        $this->client->request('POST', "/project/{$projectId}/copy-wish/{$wish->getId()}", ['_token' => $copyToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        /** @var GiftIdea $idea */
        $idea = $this->em->getRepository(GiftIdea::class)->findOneBy(['giftProject' => $project]);
        self::assertNotNull($idea);
        $ideaId = $idea->getId();

        // 6. Edit gift idea via HTMX + display fragment
        $editIdeaCrawler = $this->client->request('GET', "/project/idea/{$ideaId}/edit", [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();
        $ideaForm = $editIdeaCrawler->selectButton('Enregistrer')->form([
            'gift_idea[name]' => 'Souhait Modifié',
            'gift_idea[price]' => '60.00',
        ]);
        $this->client->submit($ideaForm, [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', "/project/idea/{$ideaId}/display");
        self::assertResponseIsSuccessful();

        // 7. Add, show, edit, and delete a comment on the idea
        $ideasCrawler = $this->client->request('GET', "/project/{$projectId}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $commentForm = $ideasCrawler->filter("form[action$='/idea/{$ideaId}/comment']")->form([
            'comment[content]' => 'Super idée de cadeau !',
        ]);
        $this->client->submit($commentForm, [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        $comment = $this->em->getRepository(\App\Entity\Comment::class)->findOneBy(['giftIdea' => $idea]);
        self::assertNotNull($comment);
        $commentId = $comment->getId();

        $this->client->request('GET', "/project/comment/{$commentId}");
        self::assertResponseIsSuccessful();

        $editCommentCrawler = $this->client->request('GET', "/project/comment/{$commentId}/edit", [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();
        $cForm = $editCommentCrawler->filter('form')->form([
            'comment[content]' => 'Commentaire édité',
        ]);
        $this->client->submit($cForm, [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        $commentViewCrawler = $this->client->request('GET', "/project/comment/{$commentId}");
        $deleteCommentUrl = $commentViewCrawler->filter('button[hx-delete]')->attr('hx-delete');
        $this->client->request('DELETE', $deleteCommentUrl, [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        // 8. Change idea status to SELECTED
        $ideasCrawler = $this->client->request('GET', "/project/{$projectId}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $statusToken = $ideasCrawler->filter("form[action$='/status/SELECTED'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/idea/{$ideaId}/status/SELECTED", ['_token' => $statusToken]);
        self::assertResponseRedirects("/project/{$projectId}?tab=ideas");

        // 9. Lock, Unlock, then Lock again
        $ideasCrawler = $this->client->request('GET', "/project/{$projectId}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $lockToken = $ideasCrawler->filter("form[action$='/lock'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$projectId}/lock", ['_token' => $lockToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        $ideasCrawler = $this->client->request('GET', "/project/{$projectId}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $unlockToken = $ideasCrawler->filter("form[action$='/unlock'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$projectId}/unlock", ['_token' => $unlockToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', "/project/{$projectId}/lock", ['_token' => $lockToken]);
        self::assertResponseRedirects("/project/{$projectId}?tab=ideas");

        // 10. Mark idea as purchased
        $ideasCrawler = $this->client->request('GET', "/project/{$projectId}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $purchaseToken = $ideasCrawler->filter("form[action$='/mark-purchased'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/idea/{$ideaId}/mark-purchased", [
            '_token' => $purchaseToken,
            'price' => '58.50',
        ], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        // 11. Mark project as read & delivered
        $activityCrawler = $this->client->request('GET', "/project/{$projectId}?tab=activite", [], [], ['HTTP_HX-Request' => 'true']);
        $markReadToken = $activityCrawler->filter("form[action$='/mark-read'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$projectId}/mark-read", ['_token' => $markReadToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        $detailsCrawler = $this->client->request('GET', "/project/{$projectId}?tab=details", [], [], ['HTTP_HX-Request' => 'true']);
        $deliverToken = $detailsCrawler->filter("form[action$='/mark-delivered'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$projectId}/mark-delivered", ['_token' => $deliverToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testLeaveAndRemoveParticipantAndDeleteIdeaAndCreateFromWish(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $p1 = $this->createUser('p1@example.com', 'P1');
        $p2 = $this->createUser('p2@example.com', 'P2');
        $recipient = $this->createUser('recipient@example.com', 'Recipient');

        $share = new \App\Entity\WishlistShare()->setOwner($recipient)->setRecipient($owner);
        $this->em->persist($share);

        $wish = new \App\Entity\Wish()->setName('Vélo')->setPrice('200.00')->setUser($recipient);
        $this->em->persist($wish);

        $project = new GiftProject();
        $project->setName('Projet Test')->setOwner($owner)->addParticipant($p1)->addParticipant($p2);
        $this->em->persist($project);

        $idea = new GiftIdea();
        $idea->setName('Idée à supprimer')->setGiftProject($project)->setProposer($owner);
        $this->em->persist($idea);
        $this->em->flush();

        // 1. P1 leaves the project
        $this->client->loginUser($p1);
        $detailsCrawler = $this->client->request('GET', "/project/{$project->getId()}?tab=details", [], [], ['HTTP_HX-Request' => 'true']);
        $leaveToken = $detailsCrawler->filter("form[action$='/leave'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$project->getId()}/leave", ['_token' => $leaveToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        // 2. Owner removes P2 and deletes the idea
        $this->client->loginUser($owner);
        $detailsCrawler = $this->client->request('GET', "/project/{$project->getId()}?tab=details", [], [], ['HTTP_HX-Request' => 'true']);
        $removeToken = $detailsCrawler->filter("form[action*='/remove-participant/'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/{$project->getId()}/remove-participant/{$p2->getId()}", ['_token' => $removeToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        $ideasCrawler = $this->client->request('GET', "/project/{$project->getId()}?tab=ideas", [], [], ['HTTP_HX-Request' => 'true']);
        $deleteIdeaUrl = $ideasCrawler->filter('button[hx-delete]')->attr('hx-delete');
        $this->client->request('DELETE', $deleteIdeaUrl, [], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        // 3. Create project directly from contact's wishlist
        $contactViewCrawler = $this->client->request('GET', "/contact/view/{$recipient->getId()}");
        self::assertResponseIsSuccessful();
        $createFromWishToken = $contactViewCrawler->filter("form[action*='/create-from-wish/'] input[name='_token']")->attr('value');
        $this->client->request('POST', "/project/create-from-wish/{$recipient->getId()}/{$wish->getId()}", [
            '_token' => $createFromWishToken,
        ], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);
    }
}
