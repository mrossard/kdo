<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Wish;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class WishType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom du souhait',
            'attr' => [
                'class' => 'input',
                'placeholder' => 'Ex: Une licorne en peluche',
            ],
            'constraints' => [
                new NotBlank(message: 'Le nom du souhait est obligatoire.'),
            ],
        ])->add('description', TextareaType::class, [
            'label' => 'Description',
            'required' => false,
            'attr' => [
                'class' => 'textarea',
                'rows' => 4,
                'placeholder' => 'Détails, taille, couleur...',
            ],
        ])->add('url', UrlType::class, [
            'label' => 'Lien (URL)',
            'required' => false,
            'attr' => [
                'class' => 'input',
                'placeholder' => 'https://...',
            ],
        ])->add('price', MoneyType::class, [
            'label' => 'Prix estimé',
            'required' => false,
            'currency' => 'EUR',
            'attr' => [
                'class' => 'input',
                'placeholder' => '25.00',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Wish::class,
        ]);
    }
}
