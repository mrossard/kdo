<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\WishlistShare;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Create main user
        $manu = new User();
        $manu->setEmail('manu@test.com');
        $manu->setRoles(['ROLE_USER']);
        $manu->setPassword($this->passwordHasher->hashPassword($manu, 'manu'));
        $manager->persist($manu);

        // Create recipient user
        $destinataire = new User();
        $destinataire->setEmail('destinataire@test.com');
        $destinataire->setRoles(['ROLE_USER']);
        $destinataire->setPassword($this->passwordHasher->hashPassword($destinataire, 'password'));
        $manager->persist($destinataire);

        // Create another user just for noise
        $other = new User();
        $other->setEmail('other@test.com');
        $other->setRoles(['ROLE_USER']);
        $other->setPassword($this->passwordHasher->hashPassword($other, 'password'));
        $manager->persist($other);

        // Share destinataire's list with manu
        $share = new WishlistShare();
        $share->setOwner($destinataire);
        $share->setRecipient($manu);
        $manager->persist($share);

        $manager->flush();
    }
}
