<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AddParticipantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $currentUser */
        $currentUser = $options['current_user'];

        $builder->add('participant', EntityType::class, [
            'class' => User::class,
            'choice_label' => 'displayName',
            'label' => 'Inviter un participant',
            'query_builder' => static fn(UserRepository $er) => $er
                ->createQueryBuilder('u')
                ->leftJoin('u.sentShares', 's_sent')
                ->leftJoin('u.receivedShares', 's_received')
                ->where('s_sent.recipient = :me OR s_received.owner = :me')
                ->andWhere('u != :me')
                ->setParameter('me', $currentUser)
                ->orderBy('u.firstname', 'ASC')
                ->addOrderBy('u.lastname', 'ASC'),
            'placeholder' => 'Sélectionnez un contact',
            'constraints' => [
                new NotBlank(message: 'Veuillez sélectionner un participant'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
            'current_user' => null, // Required option
        ]);

        $resolver->setAllowedTypes('current_user', [User::class]);
    }
}
