<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GiftIdea;
use Symfony\Component\Form\AbstractType;
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
        $builder->add('name', TextType::class, [
            'label' => 'Nom de l\'idée',
            'constraints' => [
                new NotBlank(message: 'Le nom de l\'idée est obligatoire.'),
            ],
            'attr' => ['placeholder' => 'Ex: Livre sur les chats'],
        ])->add('description', TextareaType::class, [
            'label' => 'Description',
            'required' => false,
            'attr' => ['placeholder' => 'Détails, pourquoi ce cadeau...'],
        ])->add('url', UrlType::class, [
            'label' => 'Lien produit (URL)',
            'required' => false,
            'attr' => ['placeholder' => 'https://example.com/produit-chat'],
        ])->add('price', MoneyType::class, [
            'label' => 'Prix estimé',
            'required' => false,
            'currency' => 'EUR',
            'attr' => ['placeholder' => '29.99'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GiftIdea::class,
        ]);
    }
}
