<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{email: string}>
 */
final class PasswordResetRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Correo electrónico',
            'attr' => ['autocomplete' => 'email', 'inputmode' => 'email', 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Indica tu correo electrónico.'),
                new Assert\Email(message: 'El correo no tiene un formato válido.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }
}
