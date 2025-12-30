<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, [
            'label' => 'Mot de passe actuel',
            'mapped' => false,
            'constraints' => [
                new NotBlank(message: 'Veuillez entrer votre mot de passe actuel'),
                new UserPassword(message: 'Mot de passe actuel incorrect'),
            ],
            'attr' => ['autocomplete' => 'current-password', 'class' => 'input'],
        ])->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'attr' => ['autocomplete' => 'new-password', 'class' => 'input'],
                'label' => 'Nouveau mot de passe',
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer un mot de passe'),
                    new Length(
                        min: 6,
                        minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères',
                        max: 4096,
                    ),
                ],
            ],
            'second_options' => [
                'attr' => ['autocomplete' => 'new-password', 'class' => 'input'],
                'label' => 'Répéter le mot de passe',
            ],
            'invalid_message' => 'Les mots de passe doivent correspondre.',
            'mapped' => false,
        ]);
    }
}
