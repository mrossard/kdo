<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Entity\Wish;
use App\Entity\WishlistShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManagedPersonControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'TRUNCATE TABLE gift_idea_contribution, vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, managed_person, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
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

    private function createMutualShare(User $a, User $b): void
    {
        $share1 = new WishlistShare();
        $share1->setOwner($a)->setRecipient($b);
        $this->em->persist($share1);

        $share2 = new WishlistShare();
        $share2->setOwner($b)->setRecipient($a);
        $this->em->persist($share2);
    }

    public function testManagedPersonCrudAndWishManagement(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $this->em->flush();

        // 1. Alice creates a managed person "Léo"
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/managed-person/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer la liste')->form([
            'managed_person[name]' => 'Léo',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        $leo = $this->em->getRepository(ManagedPerson::class)->findOneBy(['name' => 'Léo']);
        self::assertNotNull($leo);
        self::assertSame($alice->getId(), $leo->getManager()?->getId());

        // 2. Alice adds a wish for Léo
        $crawler = $this->client->request('GET', '/wish/new?managedPerson=' . $leo->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Léo', $crawler->text());

        $wishForm = $crawler->selectButton('Créer')->form([
            'wish[name]' => 'Vélo rouge',
            'wish[price]' => '120.00',
        ]);
        $this->client->submit($wishForm);
        self::assertResponseRedirects('/wish/person/' . $leo->getId());

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vélo rouge', $crawler->text());

        // 3. Alice's own wishlist does NOT contain Léo's wish
        $ownCrawler = $this->client->request('GET', '/wish');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Léo', $ownCrawler->text()); // tab is visible
        self::assertStringNotContainsString('Vélo rouge', $ownCrawler->text());

        // 4. Alice renames Léo to "Léo Junior"
        $editCrawler = $this->client->request('GET', '/managed-person/' . $leo->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($editCrawler->selectButton('Enregistrer')->form([
            'managed_person[name]' => 'Léo Junior',
        ]));
        self::assertResponseRedirects('/wish/person/' . $leo->getId());

        // 5. Bob cannot access or edit Alice's managed person
        $this->client->loginUser($bob);
        $this->client->request('GET', '/wish/person/' . $leo->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/managed-person/' . $leo->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        // 6. Alice deletes Léo Junior
        $this->client->loginUser($alice);
        $listCrawler = $this->client->request('GET', '/wish/person/' . $leo->getId());
        $deleteToken = $listCrawler
            ->filter('form[action="/managed-person/' . $leo->getId() . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/managed-person/' . $leo->getId() . '/delete', [
            '_token' => $deleteToken,
        ]);
        self::assertResponseRedirects('/wish');

        $this->em->clear();
        self::assertNull($this->em->getRepository(ManagedPerson::class)->find($leo->getId()));
    }

    public function testContactCanViewReserveAndCreateProjectFromManagedPersonWish(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $stranger = $this->createUser('stranger@example.com', 'Stranger');
        $this->createMutualShare($alice, $bob);

        $emma = new ManagedPerson();
        $emma->setName('Emma')->setManager($alice);
        $this->em->persist($emma);

        $wish = new Wish();
        $wish->setName('Peluche Dragon')->setPrice('35.00')->setManagedPerson($emma);
        $this->em->persist($wish);
        $this->em->flush();

        // Stranger cannot view Emma's wishlist
        $this->client->loginUser($stranger);
        $this->client->request('GET', '/contact/managed/' . $emma->getId());
        self::assertResponseStatusCodeSame(403);

        // Bob sees Emma on /contact/ and can view her wishlist
        $this->client->loginUser($bob);
        $contactsCrawler = $this->client->request('GET', '/contact/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Emma', $contactsCrawler->text());
        $emmaTag = $contactsCrawler->filter('a[href="/contact/managed/' . $emma->getId() . '"]');
        self::assertStringContainsString('(1)', $emmaTag->text());
        self::assertStringContainsString('is-info', (string) $emmaTag->attr('class'));

        $viewCrawler = $this->client->request('GET', '/contact/managed/' . $emma->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Peluche Dragon', $viewCrawler->text());

        // Bob reserves Emma's wish
        $reserveToken = $viewCrawler
            ->filter('form[action="/contact/wish/' . $wish->getId() . '/toggle-reserve"] input[name="_token"]')
            ->attr('value');
        $this->client->request('POST', '/contact/wish/' . $wish->getId() . '/toggle-reserve', [
            '_token' => $reserveToken,
        ]);
        self::assertResponseRedirects('/contact/managed/' . $emma->getId());

        $this->em->clear();
        $updatedWish = $this->em->getRepository(Wish::class)->find($wish->getId());
        self::assertSame($bob->getId(), $updatedWish?->getReservedBy()?->getId());

        // Alice (manager) sees the reservation tag on Emma's managed list
        $this->client->loginUser($alice);
        $managerCrawler = $this->client->request('GET', '/wish/person/' . $emma->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Réservé par Bob', $managerCrawler->text());

        // Bob converts his reservation into a GiftProject for Emma
        $this->client->loginUser($bob);
        $viewCrawler = $this->client->request('GET', '/contact/managed/' . $emma->getId());
        $createProjectToken = $viewCrawler
            ->filter(
                'form[action="/project/create-from-managed-wish/' . $emma->getId() . '/' . $wish->getId() . '"] input[name="_token"]',
            )
            ->attr('value');

        $this->client->request(
            'POST',
            '/project/create-from-managed-wish/' . $emma->getId() . '/' . $wish->getId(),
            ['_token' => $createProjectToken],
        );
        self::assertResponseStatusCodeSame(302);

        $projectCrawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Emma', $projectCrawler->text());
        self::assertStringContainsString('Liste de souhaits', $projectCrawler->text());

        $this->em->clear();
        $project = $this->em->getRepository(GiftProject::class)->findOneBy(['managedRecipient' => $emma]);
        self::assertNotNull($project);
        self::assertSame('Emma', $project->getRecipientDisplayName());

        // Viewing the liste_souhaits tab on the project works
        $tabCrawler = $this->client->request('GET', '/project/' . $project->getId() . '?tab=liste_souhaits');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Peluche Dragon', $tabCrawler->text());
    }

    public function testCreateGiftProjectWithManagedRecipientForm(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $emma = new ManagedPerson();
        $emma->setName('Emma')->setManager($alice);
        $this->em->persist($emma);
        $this->em->flush();

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/project/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer le projet')->form([
            'gift_project[name]' => 'Noël Emma',
            'gift_project[managedRecipient]' => (string) $emma->getId(),
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(302);

        $this->em->clear();
        $project = $this->em->getRepository(GiftProject::class)->findOneBy(['name' => 'Noël Emma']);
        self::assertNotNull($project);
        self::assertSame($emma->getId(), $project->getManagedRecipient()?->getId());
    }

    public function testCoManagerCanManageListAndShareWithOwnContacts(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');
        $bob = $this->createUser('bob@example.com', 'Bob');
        $mamie = $this->createUser('mamie@example.com', 'Mamie');
        $this->createMutualShare($alice, $bob);
        $this->createMutualShare($bob, $mamie);

        $leo = new ManagedPerson();
        $leo->setName('Léo')->setManager($alice);
        $this->em->persist($leo);
        $this->em->flush();

        // Alice adds Bob as co-manager via edit form
        $this->client->loginUser($alice);
        $editCrawler = $this->client->request('GET', '/managed-person/' . $leo->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $form = $editCrawler->selectButton('Enregistrer')->form([
            'managed_person[name]' => 'Léo',
            'managed_person[coManagers]' => [(string) $bob->getId()],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/wish/person/' . $leo->getId());

        $this->em->clear();
        $reloadedLeo = $this->em->getRepository(ManagedPerson::class)->find($leo->getId());
        self::assertNotNull($reloadedLeo);
        self::assertCount(1, $reloadedLeo->getCoManagers());

        // Bob sees Léo in his wishlist tabs and can add a wish
        $this->client->loginUser($bob);
        $bobListCrawler = $this->client->request('GET', '/wish/person/' . $leo->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alice, Bob', $bobListCrawler->text());

        $newWishCrawler = $this->client->request('GET', '/wish/new?managedPerson=' . $leo->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($newWishCrawler->selectButton('Créer')->form([
            'wish[name]' => 'Train en bois',
            'wish[price]' => '45.00',
        ]));
        self::assertResponseRedirects('/wish/person/' . $leo->getId());

        // Bob (co-manager) cannot delete Léo's list (only primary manager Alice can)
        $this->client->request('POST', '/managed-person/' . $leo->getId() . '/delete', [
            '_token' => 'invalid_or_not',
        ]);
        self::assertResponseStatusCodeSame(403);

        // Mamie (contact of Bob, not Alice) sees Léo under Bob in Contacts and can view + reserve
        $this->client->loginUser($mamie);
        $mamieContactsCrawler = $this->client->request('GET', '/contact/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Léo', $mamieContactsCrawler->text());

        $mamieViewCrawler = $this->client->request('GET', '/contact/managed/' . $leo->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Train en bois', $mamieViewCrawler->text());

        $this->em->clear();
        $wish = $this->em->getRepository(Wish::class)->findOneBy(['name' => 'Train en bois']);
        self::assertNotNull($wish);

        $reserveToken = $mamieViewCrawler
            ->filter('form[action="/contact/wish/' . $wish->getId() . '/toggle-reserve"] input[name="_token"]')
            ->attr('value');
        $this->client->request('POST', '/contact/wish/' . $wish->getId() . '/toggle-reserve', [
            '_token' => $reserveToken,
        ]);
        self::assertResponseRedirects('/contact/managed/' . $leo->getId());

        // Co-manager Bob cannot reserve a wish on a list he manages
        $this->client->loginUser($bob);
        $this->client->request('POST', '/contact/wish/' . $wish->getId() . '/toggle-reserve', [
            '_token' => 'any',
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
