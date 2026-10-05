<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{currentPassword: string, plainPassword: string}>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Contraseña actual',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe tu contraseña actual.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Nueva contraseña',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Repite la nueva contraseña',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Elige una contraseña.'),
                    new Assert\Length(
                        min: 10,
                        max: 4096,
                        minMessage: 'La contraseña debe tener al menos {{ limit }} caracteres.',
                    ),
                ],
            ])
        ;
    }
}
