<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GiftIdea;
use App\Enum\WishPriority;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class GiftIdeaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de l\'idée',
                'constraints' => [
                    new NotBlank(message: 'Le nom de l\'idée est obligatoire.'),
                ],
                'attr' => ['placeholder' => 'Ex: Livre sur les chats'],
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priorité',
                'choices' => [
                    WishPriority::LOW->getLabel() => WishPriority::LOW->value,
                    WishPriority::NORMAL->getLabel() => WishPriority::NORMAL->value,
                    WishPriority::HIGH->getLabel() => WishPriority::HIGH->value,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['placeholder' => 'Détails, pourquoi ce cadeau...'],
            ])
            ->add('url', UrlType::class, [
                'label' => 'Lien produit (URL)',
                'required' => false,
                'attr' => ['placeholder' => 'https://example.com/produit-chat'],
            ])
            ->add('imageUrl', UrlType::class, [
                'label' => 'Lien de l\'image (optionnel)',
                'required' => false,
                'attr' => ['placeholder' => 'https://example.com/image.jpg'],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Prix estimé',
                'required' => false,
                'currency' => 'EUR',
                'attr' => ['placeholder' => '29.99'],
            ])
            ->add('isCrowdfunded', CheckboxType::class, [
                'label' => 'Mode cagnotte (financement participatif libre)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GiftIdea::class,
        ]);
    }
}
