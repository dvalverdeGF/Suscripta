<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{plainPassword: string}>
 */
final class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Nueva contraseña',
                'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
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
        ]);
    }
}
