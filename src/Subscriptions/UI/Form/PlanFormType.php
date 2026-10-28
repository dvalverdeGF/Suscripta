<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Subscriptions\Domain\Entity\Plan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alta y edición de un plan del catálogo comercial.
 *
 * ## El código no se escribe: se elige de una lista corta
 *
 * Un plan del catálogo es la cara comercial de un `OrganizationPlan`, así que
 * las opciones son exactamente los planes que la aplicación sabe aplicar y que
 * todavía no tienen ficha. Dejar un campo de texto libre permitiría crear un
 * plan que nadie puede contratar.
 *
 * @extends AbstractType<PlanFormData>
 */
final class PlanFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', ChoiceType::class, [
                'label' => 'Plan de la aplicación',
                'choices' => $options['available_codes'],
                'choice_label' => static fn (OrganizationPlan $code): string => $code->label(),
                'choice_value' => static fn (?OrganizationPlan $code): ?string => $code?->value,
                'help' => 'Los límites de este plan se definen en config/packages/entitlements.yaml, no aquí.',
                'constraints' => [new Assert\NotNull(message: 'Elige a qué plan de la aplicación corresponde.')],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre comercial',
                'attr' => ['autofocus' => true, 'placeholder' => 'Profesional'],
                'constraints' => [
                    new Assert\NotBlank(message: 'El plan necesita un nombre.'),
                    new Assert\Length(max: Plan::MAX_NAME_LENGTH),
                ],
            ])
            ->add('tagline', TextType::class, [
                'label' => 'Gancho',
                'required' => false,
                'attr' => ['placeholder' => 'Para quien vive de sus suscripciones'],
                'help' => 'Una línea. Es lo primero que se lee en la tarjeta de precios.',
                'constraints' => [new Assert\Length(max: Plan::MAX_TAGLINE_LENGTH)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Opcional. Se enseña al abrir el detalle del plan.',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Orden',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
                'help' => 'Menor primero. El plan de entrada suele ir en 0.',
                'constraints' => [
                    new Assert\NotNull(message: 'Indica el orden.'),
                    new Assert\GreaterThanOrEqual(value: 0),
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Publicado',
                'required' => false,
                'help' => 'Un plan retirado deja de ofrecerse, pero quien lo tenga contratado lo conserva.',
            ])
            ->add('isHighlighted', CheckboxType::class, [
                'label' => 'Destacado',
                'required' => false,
                'help' => 'Se marca como recomendado en la página de precios.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlanFormData::class,
            'available_codes' => OrganizationPlan::cases(),
        ]);

        $resolver->setAllowedTypes('available_codes', 'array');
    }
}
