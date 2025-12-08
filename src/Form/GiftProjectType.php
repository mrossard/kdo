<?php

namespace App\Form;

use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\WishlistShare;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class GiftProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $currentUser */
        $currentUser = $options['current_user'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du projet',
                'attr' => ['placeholder' => 'Ex: Anniversaire de Maman'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description / Idées',
                'required' => false,
            ])
            ->add('recipient', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'displayName',
                'label' => 'Destinataire (parmi ceux qui partagent leur liste avec vous)',
                'query_builder' => function (UserRepository $er) use ($currentUser) {
                    // Find users who have shared their list WITH me ($currentUser)
                    // So we look for WishlistShare where recipient = $currentUser
                    // And select the owner of that share.
                    
                    return $er->createQueryBuilder('u')
                        ->join('u.sentShares', 's') // s is a WishlistShare where s.owner = u
                        ->where('s.recipient = :me')
                        ->setParameter('me', $currentUser)
                        ->orderBy('u.email', 'ASC');
                },
                'placeholder' => 'Sélectionnez un destinataire',
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner un destinataire'),
                ],
                'help' => 'Le destinataire ne verra PAS ce projet.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GiftProject::class,
            'current_user' => null, // Required option
        ]);

        $resolver->setAllowedTypes('current_user', [User::class]);
    }
}