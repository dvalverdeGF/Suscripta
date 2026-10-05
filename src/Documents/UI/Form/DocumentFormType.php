<?php

declare(strict_types=1);

namespace App\Documents\UI\Form;

use App\Documents\Domain\Enum\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Subida manual de un documento.
 *
 * La subida manual existe porque no todo llega por correo: hay facturas en
 * papel, contratos firmados y justificantes que el usuario guarda a mano.
 *
 * @extends AbstractType<DocumentFormData>
 */
final class DocumentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Fichero',
                'help' => 'PDF, imagen o texto. Hasta 20 MB.',
                'constraints' => [
                    new Assert\NotNull(message: 'Selecciona un fichero.'),
                    new Assert\File(
                        maxSize: '20M',
                        maxSizeMessage: 'El fichero supera el tamaño máximo de 20 MB.',
                    ),
                ],
            ])
            ->add('type', EnumType::class, [
                'label' => 'Tipo de documento',
                'class' => DocumentType::class,
                'choice_label' => static fn (DocumentType $type): string => $type->label(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona un tipo.')],
            ])
            ->add('serviceId', ChoiceType::class, [
                'label' => 'Servicio',
                'required' => false,
                'placeholder' => 'Sin asignar',
                'choices' => $options['service_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
                'help' => 'Puedes asignarlo más tarde.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentFormData::class,
            'service_choices' => [],
        ]);

        $resolver->setAllowedTypes('service_choices', 'array');
    }
}
