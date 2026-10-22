<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nombre de la organización.
 *
 * Un solo campo: crear una organización no es un alta complicada, es darle un
 * nombre. El slug se genera solo a partir de este nombre.
 *
 * @extends AbstractType<array{name: string}>
 */
final class OrganizationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre de la organización',
                'attr' => [
                    'autocomplete' => 'organization',
                    'autofocus' => true,
                    'maxlength' => 120,
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe un nombre para la organización.'),
                    new Assert\Length(max: 120, maxMessage: 'El nombre es demasiado largo (máximo 120 caracteres).'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'empty_data' => static fn (): array => ['name' => ''],
        ]);
    }
}
