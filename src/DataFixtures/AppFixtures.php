<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\Wish;
use App\Entity\WishlistShare;
use App\Enum\WishPriority;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $manu = new User()
            ->setEmail('manu@test.com')
            ->setFirstname('Manu')
            ->setLastname('Rossard')
            ->setRoles(['ROLE_ADMIN']);
        $manu->setPassword($this->passwordHasher->hashPassword($manu, 'Manu!Kdo2026'));
        $manager->persist($manu);

        $destinataire = new User()
            ->setEmail('destinataire@test.com')
            ->setFirstname('Alice')
            ->setLastname('Martin')
            ->setRoles(['ROLE_USER']);
        $destinataire->setPassword($this->passwordHasher->hashPassword($destinataire, 'Alice!Kdo2026'));
        $manager->persist($destinataire);

        $other = new User()
            ->setEmail('other@test.com')
            ->setFirstname('Bob')
            ->setLastname('Durand')
            ->setRoles(['ROLE_USER']);
        $other->setPassword($this->passwordHasher->hashPassword($other, 'Bob!Kdo2026'));
        $manager->persist($other);

        // Mutual shares between Manu, Alice and Bob
        foreach ([
            [$destinataire, $manu],
            [$manu,         $destinataire],
            [$other,        $manu],
            [$manu,         $other],
        ] as [$owner, $recipient]) {
            $share = new WishlistShare()
                ->setOwner($owner)
                ->setRecipient($recipient);
            $manager->persist($share);
        }

        // Sample wishes for Alice
        $wish1 = new Wish()
            ->setName('Casque audio sans fil')
            ->setDescription('Pour écouter de la musique dans les transports.')
            ->setPrice('149.90')
            ->setPriority(WishPriority::HIGH->value)
            ->setUser($destinataire);
        $manager->persist($wish1);

        $wish2 = new Wish()
            ->setName('Roman de science-fiction')
            ->setPrice('22.00')
            ->setPriority(WishPriority::NORMAL->value)
            ->setUser($destinataire);
        $manager->persist($wish2);

        // Sample gift project organized by Manu with Bob for Alice
        $project = new GiftProject()
            ->setName('Anniversaire Alice')
            ->setDescription('Idée de cadeau commun pour Alice.')
            ->setOwner($manu)
            ->setRecipient($destinataire)
            ->addParticipant($other);
        $manager->persist($project);

        $idea = GiftIdea::createFromWish($wish1, $project, $manu);
        $manager->persist($idea);

        $comment = new Comment()
            ->setProject($project)
            ->setAuthor($other)
            ->setContent('Super idée, je participe volontiers !');
        $manager->persist($comment);

        $manager->flush();
    }
}
