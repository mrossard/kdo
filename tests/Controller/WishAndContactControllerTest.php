<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContactRequest;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\Wish;
use App\Entity\WishlistShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WishAndContactControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'TRUNCATE TABLE gift_idea_contribution, vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
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

    public function testWishListHidesDeliveredWishesByDefaultAndNeverSpoilsInProgressGifts(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');

        // Wish 1: In progress (not delivered yet) -> must stay visible on Alice's list so it doesn't spoil the surprise!
        $wishInProgress = new Wish();
        $wishInProgress->setName('Casque Audio')->setPrice('80.00')->setUser($alice);
        $this->em->persist($wishInProgress);

        $projectInProgress = new GiftProject();
        $projectInProgress->setName('Noël Alice')->setOwner($bob)->setRecipient($alice);
        $this->em->persist($projectInProgress);

        $idea1 = new GiftIdea();
        $idea1
            ->setName('Casque Audio')
            ->setGiftProject($projectInProgress)
            ->setProposer($bob)
            ->setFromWish($wishInProgress)
            ->setStatus(GiftIdea::STATUS_SELECTED);
        $this->em->persist($idea1);

        // Wish 2: Already delivered -> hidden by default, visible when showDelivered=1
        $wishDelivered = new Wish();
        $wishDelivered->setName('Livre Cuisine')->setPrice('25.00')->setUser($alice);
        $this->em->persist($wishDelivered);

        $projectDelivered = new GiftProject();
        $projectDelivered
            ->setName('Anniv Alice')
            ->setOwner($bob)
            ->setRecipient($alice)
            ->setLocked(true)
            ->setDeliveredAt(new \DateTimeImmutable());
        $this->em->persist($projectDelivered);

        $idea2 = new GiftIdea();
        $idea2
            ->setName('Livre Cuisine')
            ->setGiftProject($projectDelivered)
            ->setProposer($bob)
            ->setFromWish($wishDelivered)
            ->setStatus(GiftIdea::STATUS_SELECTED);
        $this->em->persist($idea2);

        $this->em->flush();

        $this->client->loginUser($alice);

        // Default view: shows in-progress wish (no spoiler!), hides delivered wish
        $crawler = $this->client->request('GET', '/wish');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Casque Audio', $crawler->text());
        self::assertStringNotContainsString('Livre Cuisine', $crawler->text());

        // With showDelivered=1: shows both
        $crawlerWithDelivered = $this->client->request('GET', '/wish?showDelivered=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Casque Audio', $crawlerWithDelivered->text());
        self::assertStringContainsString('Livre Cuisine', $crawlerWithDelivered->text());
    }

    public function testAcceptContactRequestCreatesMutualWishlistShares(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');

        $contactRequest = new ContactRequest();
        $contactRequest
            ->setSender($alice)
            ->setRecipient($bob)
            ->setStatus(ContactRequest::STATUS_PENDING);
        $this->em->persist($contactRequest);
        $this->em->flush();

        $this->client->loginUser($bob);
        $crawler = $this->client->request('GET', '/contact/');
        self::assertResponseIsSuccessful();

        $acceptToken = $crawler
            ->filter('form[action$="/accept"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/contact/request/' . $contactRequest->getId() . '/accept', [
            '_token' => $acceptToken,
        ]);
        self::assertResponseRedirects('/contact/');

        $this->em->clear();
        $shares = $this->em->getRepository(WishlistShare::class)->findAll();
        self::assertCount(2, $shares);

        // Alice can now view Bob's wishlist
        $this->client->loginUser($alice);
        $this->client->request('GET', '/contact/view/' . $bob->getId());
        self::assertResponseIsSuccessful();
    }

    public function testWishCrudNewShowEditDelete(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $this->em->flush();

        $this->client->loginUser($alice);

        // 1. New wish + autofill route test
        $this->client->request('POST', '/wish/autofill', [
            'wish' => ['url' => ''],
        ]);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/wish/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Créer')->form([
            'wish[name]' => 'Machine à café',
            'wish[description]' => 'Expresso broyeur',
            'wish[url]' => 'https://example.com/cafe',
            'wish[imageUrl]' => 'https://example.com/cafe.jpg',
            'wish[priority]' => '3',
            'wish[price]' => '350.00',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/wish');

        // Sort wish index by priority
        $this->client->request('GET', '/wish?sort=priority&direction=DESC');
        self::assertResponseIsSuccessful();

        /** @var Wish $wish */
        $wish = $this->em->getRepository(Wish::class)->findOneBy(['name' => 'Machine à café']);
        self::assertNotNull($wish);
        self::assertSame(3, $wish->getPriority());
        self::assertSame('https://example.com/cafe.jpg', $wish->getImageUrl());

        // 1b. Another user (Bob) cannot view, edit, or delete Alice's wish
        $this->client->loginUser($bob);
        $this->client->request('GET', '/wish/' . $wish->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/wish/' . $wish->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/wish/' . $wish->getId(), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($alice);

        // 2. Show wish
        $this->client->request('GET', '/wish/' . $wish->getId());
        self::assertResponseIsSuccessful();

        // 3. Edit wish
        $editCrawler = $this->client->request('GET', '/wish/' . $wish->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $editForm = $editCrawler->selectButton('Mettre à jour')->form([
            'wish[name]' => 'Machine à café Pro',
            'wish[price]' => '399.00',
        ]);
        $this->client->submit($editForm);
        self::assertResponseRedirects('/wish');

        // 4. Delete wish (HTMX)
        $showCrawler = $this->client->request('GET', '/wish/' . $wish->getId());
        $deleteToken = $showCrawler->filter('form[action$="/wish/' . $wish->getId() . '"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/wish/' . $wish->getId(), ['_token' => $deleteToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNull($this->em->getRepository(Wish::class)->find($wish->getId()));
    }

    public function testContactInviteDeclineSettleAndRemove(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $charlie = $this->createUser('charlie@example.com', 'Charlie');

        // Mutual shares between Alice and Bob
        $this->em->persist(new WishlistShare()->setOwner($alice)->setRecipient($bob));
        $this->em->persist(new WishlistShare()->setOwner($bob)->setRecipient($alice));

        // Project where Alice paid 40 € so Bob owes 20 € to Alice
        $project = new GiftProject()->setName('Cadeau Commun')->setOwner($alice)->addParticipant($bob)->setLocked(true);
        $this->em->persist($project);
        $idea = new GiftIdea()
            ->setName('Livre')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setGiftProject($project)
            ->setProposer($alice)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('40.00');
        $this->em->persist($idea);
        $this->em->flush();

        $this->client->loginUser($alice);

        // 1. Invite registered user Charlie & unregistered user via form
        $crawler = $this->client->request('GET', '/contact/');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Envoyer')->form([
            'add_contact_form[email]' => 'charlie@example.com',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/contact/');

        $crawler = $this->client->request('GET', '/contact/');
        $formUnregistered = $crawler->selectButton('Envoyer')->form([
            'add_contact_form[email]' => 'newfriend@example.com',
        ]);
        $this->client->submit($formUnregistered);
        self::assertResponseRedirects('/contact/');

        // 2. Charlie declines Alice's contact request
        $this->client->loginUser($charlie);
        $charlieCrawler = $this->client->request('GET', '/contact/');
        $declineForm = $charlieCrawler->filter('form[action$="/decline"]');
        $declineToken = $declineForm->filter('input[name="_token"]')->attr('value');
        $declineAction = $declineForm->attr('action');
        $this->client->request('POST', $declineAction, ['_token' => $declineToken]);
        self::assertResponseRedirects('/contact/');

        // 3. Alice settles Bob's debt and then removes Bob from contacts
        $this->client->loginUser($alice);
        $aliceCrawler = $this->client->request('GET', '/contact/');
        $settleForm = $aliceCrawler->filter('form[action$="/settle/' . $bob->getId() . '"]');
        $settleToken = $settleForm->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/contact/settle/' . $bob->getId(), ['_token' => $settleToken]);
        self::assertResponseRedirects('/contact/');

        $this->em->clear();
        $reimbursementActivity = $this->em->getRepository(\App\Entity\Activity::class)->findOneBy([
            'project' => $project->getId(),
            'type' => \App\Entity\Activity::TYPE_REIMBURSEMENT_CREATED,
        ]);
        self::assertNotNull($reimbursementActivity);
        self::assertSame(['amount' => '20.00'], $reimbursementActivity->getMeta());

        $aliceCrawler = $this->client->request('GET', '/contact/');
        $removeForm = $aliceCrawler->filter('form[action$="/remove/' . $bob->getId() . '"]');
        $removeToken = $removeForm->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/contact/remove/' . $bob->getId(), ['_token' => $removeToken]);
        self::assertResponseRedirects('/contact/');
    }

    public function testDirectWishReservationAndDuplicateProtection(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');

        $this->em->persist(new WishlistShare()->setOwner($owner)->setRecipient($alice));
        $this->em->persist(new WishlistShare()->setOwner($owner)->setRecipient($bob));

        $wish = new Wish()
            ->setUser($owner)
            ->setName('Appareil Photo')
            ->setPrice('450.00');
        $this->em->persist($wish);
        $this->em->flush();

        // 1. Alice reserves the wish in 1 click
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/contact/view/' . $owner->getId());
        self::assertResponseIsSuccessful();
        $reserveForm = $crawler->filter('form[action$="/toggle-reserve"]');
        $reserveToken = $reserveForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/contact/wish/' . $wish->getId() . '/toggle-reserve', [
            '_token' => $reserveToken,
        ]);
        self::assertResponseRedirects('/contact/view/' . $owner->getId());

        $this->em->clear();
        /** @var Wish $updatedWish */
        $updatedWish = $this->em->getRepository(Wish::class)->find($wish->getId());
        self::assertSame($alice->getId(), $updatedWish->getReservedBy()?->getId());
        self::assertNotNull($updatedWish->getReservedAt());

        // 2. Zero spoiler: Owner sees their wishlist without any mention of reservation
        $this->client->loginUser($owner);
        $this->client->request('GET', '/wish');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Réservé par');

        // 3. Bob sees that Alice reserved the wish and cannot reserve or create a project from it
        $this->client->loginUser($bob);
        $bobCrawler = $this->client->request('GET', '/contact/view/' . $owner->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Réservé par Alice');

        // Even if Bob crafts a POST to createProjectFromWish, it is rejected
        $this->client->request(
            'POST',
            '/project/create-from-wish/' . $owner->getId() . '/' . $wish->getId(),
            ['_token' => 'invalid_or_any'],
        );
        self::assertResponseRedirects('/contact/view/' . $owner->getId());
        $this->em->clear();
        /** @var Wish $updatedWishAfterBob */
        $updatedWishAfterBob = $this->em->getRepository(Wish::class)->find($wish->getId());
        self::assertNull($updatedWishAfterBob->getActiveProject());

        // 4. Alice converts her reserved wish into a group project -> clears solo reservation & links project
        $this->client->loginUser($alice);
        $aliceCrawler = $this->client->request('GET', '/contact/view/' . $owner->getId());
        $projectForm = $aliceCrawler->filter('form[action*="/project/create-from-wish/"]');
        $projectToken = $projectForm->filter('input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/project/create-from-wish/' . $owner->getId() . '/' . $wish->getId(),
            ['_token' => $projectToken],
        );
        self::assertResponseRedirects('/contact/view/' . $owner->getId());

        $this->em->clear();
        /** @var Wish $wishInProject */
        $wishInProject = $this->em->getRepository(Wish::class)->find($wish->getId());
        self::assertNull($wishInProject->getReservedBy());
        self::assertNotNull($wishInProject->getActiveProject());
        self::assertSame($alice->getId(), $wishInProject->getActiveProject()->getOwner()?->getId());
    }

    public function testSettleDebtDeniesNonMutualContact(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $stranger = $this->createUser('stranger@example.com', 'Stranger');
        $this->em->flush();

        $this->client->loginUser($alice);
        $this->client->request('POST', '/contact/settle/' . $stranger->getId(), [
            '_token' => 'any_token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCsrfShowsDangerFlashMessage(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');

        $share = new WishlistShare();
        $share->setOwner($bob)->setRecipient($alice);
        $this->em->persist($share);

        $wish = new Wish();
        $wish->setName('Livre')->setUser($bob);
        $this->em->persist($wish);
        $this->em->flush();

        $this->client->loginUser($alice);
        $this->client->request('POST', '/contact/wish/' . $wish->getId() . '/toggle-reserve', [
            '_token' => 'invalid_csrf_token',
        ]);

        self::assertResponseRedirects('/contact/view/' . $bob->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notification.is-danger', 'Jeton de sécurité invalide.');
    }

    public function testContactListShowsWishCountIndicatorsExcludingDelivered(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $charlie = $this->createUser('charlie@example.com', 'Charlie');

        // Mutual shares between Alice & Bob, and Alice & Charlie
        $this->em->persist(new WishlistShare()->setOwner($alice)->setRecipient($bob));
        $this->em->persist(new WishlistShare()->setOwner($bob)->setRecipient($alice));
        $this->em->persist(new WishlistShare()->setOwner($alice)->setRecipient($charlie));
        $this->em->persist(new WishlistShare()->setOwner($charlie)->setRecipient($alice));

        // Bob has 2 active wishes and 1 delivered wish
        $this->em->persist(new Wish()->setName('Vélo')->setUser($bob));
        $this->em->persist(new Wish()->setName('Montre')->setUser($bob));
        $deliveredWish = new Wish()->setName('Livre offert')->setUser($bob);
        $this->em->persist($deliveredWish);

        $deliveredProject = new GiftProject()
            ->setName('Anniv Bob')
            ->setOwner($alice)
            ->setRecipient($bob)
            ->setLocked(true)
            ->setDeliveredAt(new \DateTimeImmutable());
        $this->em->persist($deliveredProject);
        $this->em->persist(
            new GiftIdea()
                ->setName('Livre offert')
                ->setGiftProject($deliveredProject)
                ->setProposer($alice)
                ->setFromWish($deliveredWish)
                ->setStatus(GiftIdea::STATUS_SELECTED),
        );

        $this->em->flush();

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/contact/');
        self::assertResponseIsSuccessful();

        $bobListButton = $crawler->filter('a[href="/contact/view/' . $bob->getId() . '"]');
        self::assertStringContainsString('is-info', (string) $bobListButton->attr('class'));
        self::assertSame('2', trim($bobListButton->filter('.tag')->text()));

        $charlieListButton = $crawler->filter('a[href="/contact/view/' . $charlie->getId() . '"]');
        self::assertStringNotContainsString('is-info', (string) $charlieListButton->attr('class'));
        self::assertSame('0', trim($charlieListButton->filter('.tag')->text()));
    }
}
