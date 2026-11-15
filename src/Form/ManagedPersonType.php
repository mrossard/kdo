<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ManagedPersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $currentUser */
        $currentUser = $options['current_user'];
        $canManageCoManagers = $options['can_manage_co_managers'] === true;

        $builder->add('name', TextType::class, [
            'label' => 'Prénom ou nom du proche',
            'attr' => [
                'placeholder' => 'Ex : Léo, Emma...',
                'autofocus' => true,
            ],
            'help' => 'Sa liste de souhaits sera automatiquement visible par vos contacts.',
        ]);

        if ($canManageCoManagers && $currentUser !== null) {
            $builder->add('coManagers', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'displayName',
                'label' => 'Co-gestionnaires (optionnel)',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'query_builder' => static fn(UserRepository $er) => $er
                    ->createQueryBuilder('u')
                    ->join('u.sentShares', 's')
                    ->where('s.recipient = :me')
                    ->andWhere('u != :me')
                    ->setParameter('me', $currentUser)
                    ->orderBy('u.firstname', 'ASC')
                    ->addOrderBy('u.email', 'ASC'),
                'help' => 'Les co-gestionnaires peuvent aussi ajouter, modifier et supprimer des souhaits sur cette liste, et la partager avec leurs contacts.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagedPerson::class,
            'current_user' => null,
            'can_manage_co_managers' => true,
        ]);

        $resolver->setAllowedTypes('current_user', ['null', User::class]);
        $resolver->setAllowedTypes('can_manage_co_managers', ['bool']);
    }
}
