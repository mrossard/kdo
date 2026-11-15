<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GiftProject;
use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Repository\ManagedPersonRepository;
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
                'label' => 'Destinataire (utilisateur inscrit)',
                'required' => false,
                'query_builder' => static fn(UserRepository $er) => $er
                    ->createQueryBuilder('u')
                    ->join('u.sentShares', 's')
                    ->where('s.recipient = :me')
                    ->setParameter('me', $currentUser)
                    ->orderBy('u.email', 'ASC'),
                'placeholder' => 'Sélectionnez un destinataire',
                'help' => 'Si le destinataire a un compte et partage sa liste avec vous. Le destinataire ne verra PAS ce projet.',
            ])
            ->add('managedRecipient', EntityType::class, [
                'class' => ManagedPerson::class,
                'choice_label' => static fn(ManagedPerson $mp): string => $mp->isManagedBy($currentUser)
                    ? $mp->getDisplayName() . ' (géré par moi)'
                    : $mp->getDisplayName() . ' (géré par ' . ($mp->getManager()?->getDisplayName() ?? '') . ')',
                'label' => 'Ou proche avec liste gérée (ex : enfant)',
                'required' => false,
                'query_builder' => static fn(ManagedPersonRepository $er) => $er
                    ->createQueryBuilder('mp')
                    ->distinct()
                    ->leftJoin('mp.manager', 'm')
                    ->leftJoin('m.sentShares', 's')
                    ->leftJoin('mp.coManagers', 'cm')
                    ->leftJoin('cm.sentShares', 'cs')
                    ->where('mp.manager = :me OR cm = :me OR s.recipient = :me OR cs.recipient = :me')
                    ->setParameter('me', $currentUser)
                    ->orderBy('mp.name', 'ASC'),
                'placeholder' => 'Sélectionnez un proche sans compte',
                'help' => 'Proche sans compte dont vous ou un contact gérez la liste de souhaits.',
            ])
            ->add('recipientName', TextType::class, [
                'label' => 'Ou destinataire libre (sans liste)',
                'required' => false,
                'help' => 'Renseignez ce champ uniquement si vous ne sélectionnez personne ci-contre.',
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'Date cible',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'Date à laquelle le cadeau doit être remis (optionnel).',
                'attr' => ['min' => new \DateTime()->format('Y-m-d')],
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
        $selectedCount =
            ($project->getRecipient() !== null ? 1 : 0)
            + ($project->getManagedRecipient() !== null ? 1 : 0)
            + ($project->getRecipientName() !== null && $project->getRecipientName() !== '' ? 1 : 0);

        if ($selectedCount === 0) {
            $context
                ->buildViolation('Veuillez sélectionner un destinataire ou saisir un nom.')
                ->atPath('recipient')
                ->addViolation();
        }

        if ($selectedCount > 1) {
            $context
                ->buildViolation('Veuillez choisir un seul destinataire (inscrit, proche géré ou nom libre).')
                ->atPath('recipientName')
                ->addViolation();
        }
    }
}
