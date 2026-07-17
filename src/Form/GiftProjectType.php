<?php

namespace App\Form;

use App\Entity\GiftProject;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class GiftProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $currentUser */
        $currentUser = $options['current_user'];

        $builder->add('name', TextType::class, [
            'label' => 'Nom du projet',
            'attr' => ['placeholder' => 'Ex: Anniversaire de Maman'],
        ])->add('description', TextareaType::class, [
            'label' => 'Description / Idées',
            'required' => false,
        ])->add('recipient', EntityType::class, [
            'class' => User::class,
            'choice_label' => 'displayName',
            'label' => 'Destinataire (utilisateur inscrit)',
            'required' => false,
            'query_builder' => static function (UserRepository $er) use ($currentUser) {
                // Find users who have shared their list WITH me ($currentUser)
                // So we look for WishlistShare where recipient = $currentUser
                // And select the owner of that share.

                return $er
                    ->createQueryBuilder('u')
                    ->join('u.sentShares', 's')
                    ->where('s.recipient = :me')
                    ->setParameter('me', $currentUser)
                    ->orderBy('u.email', 'ASC'); // s is a WishlistShare where s.owner = u
            },
            'placeholder' => 'Sélectionnez un destinataire',
            'help' => 'Si le destinataire a un compte et partage sa liste avec vous. Le destinataire ne verra PAS ce projet.',
        ])->add('recipientName', TextType::class, [
            'label' => 'Ou destinataire libre (si pas de compte)',
            'required' => false,
            'help' => 'Renseignez ce champ uniquement si vous ne sélectionnez pas d\'utilisateur ci-dessus.',
        ])->add('targetDate', DateType::class, [
            'label' => 'Date cible',
            'required' => false,
            'widget' => 'single_text',
            'help' => 'Date à laquelle le cadeau doit être remis (optionnel).',
            'attr' => ['min' => (new \DateTime())->format('Y-m-d')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GiftProject::class,
            'current_user' => null, // Required option
            'constraints' => [
                new Callback([$this, 'validateRecipient']),
            ],
        ]);

        $resolver->setAllowedTypes('current_user', [User::class]);
    }

    public function validateRecipient(GiftProject $project, ExecutionContextInterface $context): void
    {
        if (!$project->getRecipient() && !$project->getRecipientName()) {
            $context
                ->buildViolation('Veuillez sélectionner un destinataire ou saisir un nom.')
                ->atPath('recipient')
                ->addViolation();
        }

        if ($project->getRecipient() && $project->getRecipientName()) {
            $context
                ->buildViolation('Veuillez choisir soit un destinataire inscrit, soit un nom libre, mais pas les deux.')
                ->atPath('recipientName')
                ->addViolation();
        }
    }
}
